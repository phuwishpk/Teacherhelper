<?php

namespace Tests\Feature\Exams;

use App\Domain\Documents\SourceDocuments;
use App\Domain\Exams\ExamDocuments;
use App\Domain\Exams\ExamFigures;
use App\Domain\Exams\ExamImages;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiRequest;
use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\DocumentExtraction;
use App\Models\ExamImport;
use App\Models\ExamPageImage;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Tests\TestCase;

/**
 * DESIGN §22.4, §22.15, §22.18 (build step 5): an exam file read once by
 * Gemini (exam_read through the FakeGeminiClient, cached for the school),
 * written as draft sections and questions the teacher approves, figures
 * cropped with GD from the photo itself or from a page the app rendered,
 * boxes drawn again by the teacher, and source files only for the owner.
 */
class ExamImportTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->gemini = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $this->gemini);
        config([
            'services.gemini.price_input_per_m' => '0.50',
            'services.gemini.price_output_per_m' => '3.00',
            'eduvision.usd_thb_rate' => '33',
            'services.gemini.media.document' => 'medium',
        ]);
        $this->makeExamWorld();
    }

    /** A 1000×800 white page with a red block where the fake puts figure 1 (x 100–600, y 80–400 px). */
    private static function pagePhoto(): string
    {
        $image = imagecreatetruecolor(1000, 800);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 100, 80, 600, 400, (int) imagecolorallocate($image, 220, 0, 0));
        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }

    /** A rendered PDF page (as the app uploads it): 700×1000 with a blue block. */
    private static function renderedPage(): UploadedFile
    {
        $image = imagecreatetruecolor(700, 1000);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 699, 999, (int) imagecolorallocate($image, 0, 0, 220));
        ob_start();
        imagejpeg($image, null, 90);

        return UploadedFile::fake()->createWithContent('page.jpg', (string) ob_get_clean());
    }

    private static function pdf(int $pages, string $name = 'exam.pdf'): UploadedFile
    {
        $mpdf = new Mpdf(['tempDir' => storage_path('framework/testing')]);
        for ($i = 1; $i <= $pages; $i++) {
            if ($i > 1) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML("<p>ข้อสอบ หน้า {$i}</p>");
        }

        return UploadedFile::fake()->createWithContent($name, $mpdf->Output('', 'S'));
    }

    /** @return list<int> */
    private function upload(array $files, $teacher = null): array
    {
        return array_column($this->asUser($teacher ?? $this->teacher)->post('/api/v1/documents', ['files' => $files], ['Accept' => 'application/json'])->assertCreated()->json('data'), 'id');
    }

    /** @return list<GeminiRequest> */
    private function sent(): array
    {
        return array_values(array_filter($this->gemini->requests, fn (GeminiRequest $r) => $r->purpose === 'exam_read'));
    }

    /** @return array{0: Assignment, 1: list<int>} an exam that read the red-block photo */
    private function importPhoto(): array
    {
        $exam = $this->createExam();
        $ids = $this->upload([UploadedFile::fake()->createWithContent('exam.jpg', self::pagePhoto())]);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import", ['document_ids' => $ids])->assertStatus(202);

        return [$exam, $ids];
    }

    public function test_a_photo_is_read_once_into_draft_sections_and_questions_that_need_approval(): void
    {
        $exam = $this->createExam();
        $ids = $this->upload([UploadedFile::fake()->createWithContent('exam.jpg', self::pagePhoto())]);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import/estimate", ['document_ids' => $ids])
            ->assertOk()
            ->assertJsonPath('data.pages', 1)
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.estimate.input_tokens', 2060);
        $this->assertCount(0, $this->sent(), 'the estimate is free');

        $queued = $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import", ['document_ids' => $ids])
            ->assertStatus(202)
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.extraction.purpose', 'exam')
            ->assertJsonPath('data.import.documents.0.source_document_id', $ids[0])
            ->assertJsonPath('data.applied', null);
        $this->assertCount(1, $this->sent());
        $request = $this->sent()[0];
        $this->assertSame(['medium', 16384, 0.0, 'medium'], [$request->thinkingLevel, $request->maxOutputTokens, $request->temperature, $request->images[0]->mediaResolution]);
        $call = AiCall::query()->where('purpose', 'exam_read')->sole();
        $this->assertSame(['exam_import', $exam->id], [$call->feature, $call->assignment_id]);

        // The queue is sync in tests: the read is done and applied.
        $this->asUser($this->teacher)->getJson('/api/v1/document-extractions/'.$queued->json('data.extraction.id'))
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.result.skipped.0.number', 8)
            ->assertJsonPath('data.result.skipped.0.reason_th', 'ข้อเขียนตอบ ฝนไม่ได้');
        $this->assertNotNull(ExamImport::query()->sole()->applied_at);

        $json = $this->examJson($exam);
        $this->assertSame(['mcq', 'true_false', 'numeric'], array_column($json['sections'], 'type'));
        $this->assertSame([4, null, null], array_column($json['sections'], 'option_count'));
        $this->assertSame(['digits' => 3, 'allow_negative' => false, 'allow_decimal' => true], $json['sections'][2]['numeric']);
        $this->assertSame('เลือกคำตอบที่ถูกที่สุด', $json['sections'][0]['instructions']);
        $questions = array_merge(...array_map(fn ($s) => $s['questions'], $json['sections']));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column($questions, 'position'));
        $this->assertSame(array_fill(0, 7, 'document'), array_column($questions, 'origin'));
        $this->assertSame(array_fill(0, 7, null), array_column($questions, 'approved_at'), 'every question is a draft');
        $this->assertSame([
            ['accepted_options' => [3]], ['accepted_options' => [1, 4]], null,
            ['accepted_options' => [1]], ['accepted_options' => [2]],
            ['accepted_values' => ['0.5']], null,
        ], array_column($questions, 'answer_key'), 'labels become original positions; 12345 does not fit 3 digits');
        $this->assertSame('รูป 3', $questions[0]['options'][2]['text']);
        $this->assertFalse($questions[2]['lock_options'], 'Gemini only suggests "ห้ามสลับตัวเลือก"');
        $this->assertTrue($questions[2]['lock_options_suggested']);
        $this->assertFalse($questions[0]['lock_options_suggested']);

        // Drafts block the key approval until the teacher approves them.
        $this->assertFalse($json['key_complete']);
        $this->assertSame(['not_approved'], $json['incomplete_questions'][0]['reasons']);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/answer-key/approve")
            ->assertStatus(422)->assertJsonPath('code', 'answer_key_incomplete');
        $ids = array_column($questions, 'id');
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/questions/approve", ['question_ids' => $ids])->assertOk();
        $this->asUser($this->teacher)->putJson("/api/v1/exams/{$exam->id}/answer-key", ['answers' => [
            ['question_id' => $ids[2], 'accepted_options' => [4]],
            ['question_id' => $ids[6], 'accepted_values' => ['123']],
        ]])->assertOk();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/answer-key/approve")->assertOk();
    }

    public function test_the_figures_of_a_photo_are_cropped_by_the_server_along_their_box(): void
    {
        [$exam, $ids] = $this->importPhoto();

        $page = ExamPageImage::query()->sole();
        $this->assertSame([$ids[0], 1, 1000, 800, null], [$page->source_document_id, $page->page_no, $page->width_px, $page->height_px, $page->uploaded_by]);
        $this->assertSame(ExamFigures::pagePath($page), $page->file_path);

        $q1 = Question::query()->where('assignment_id', $exam->id)->where('position', 1)->sole();
        $this->assertSame("exams/{$exam->school_id}/{$exam->id}/figures/q{$q1->id}.jpg", $q1->prompt_image_path);
        $this->assertSame(['page_image_id' => $page->id, 'source_document_id' => $ids[0], 'page_no' => 1, 'box_2d' => [100, 100, 500, 600]], $q1->figure_source);
        // box [100, 100, 500, 600] of 0–1000 plus 2% on each side: x 80–620 of 1000 px, y 64–416 of 800 px.
        $crop = imagecreatefromstring((string) ExamImages::disk()->get($q1->prompt_image_path));
        $this->assertSame([540, 352], [imagesx($crop), imagesy($crop)]);
        $center = imagecolorsforindex($crop, imagecolorat($crop, 270, 176));
        $this->assertGreaterThan(180, $center['red']);
        $this->assertLessThan(60, $center['green']);
        $corner = imagecolorsforindex($crop, imagecolorat($crop, 2, 2));
        $this->assertGreaterThan(200, $corner['green'], 'the 2% margin is white paper');

        $option = QuestionOption::query()->whereNotNull('figure_source')->sole();
        $this->assertSame(2, $option->position);
        $this->assertSame("exams/{$exam->school_id}/{$exam->id}/figures/o{$option->id}.jpg", $option->image_path);

        $json = $this->examJson($exam);
        $this->assertSame([], $json['figures_pending']);
        $this->assertSame([['id' => $page->id, 'source_document_id' => $ids[0], 'page_no' => 1, 'width_px' => 1000, 'height_px' => 800, 'available' => true]], $json['page_images']);
        $first = $json['sections'][0]['questions'][0];
        $this->assertTrue($first['has_prompt_image']);
        $this->assertFalse($first['figure_pending']);
        $this->assertSame($page->id, $first['figure_source']['page_image_id']);
        $this->assertTrue($json['sections'][0]['questions'][1]['options'][1]['has_image']);
        $this->asUser($this->teacher)->get("/api/v1/questions/{$q1->id}/image")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->asUser($this->teacher)->get("/api/v1/exam-page-images/{$page->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_purged_page_image_that_gets_a_new_file_starts_its_retention_again(): void
    {
        [$exam, $ids] = $this->importPhoto();
        $page = ExamPageImage::query()->sole();

        // Day 31: the page image file is purged (the source photo too).
        $this->travel(31)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        $this->assertNull($page->refresh()->file_path);

        // The app uploads the page again: the row is reused and its file is new, so the next purge keeps it.
        $this->asUser($this->teacher)->post("/api/v1/exams/{$exam->id}/page-images", ['source_document_id' => $ids[0], 'page_no' => 1, 'image' => self::renderedPage()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.page_image.id', $page->id);
        $this->travel(1)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        $this->assertNotNull($page->refresh()->file_path);
        ExamImages::disk()->assertExists((string) $page->file_path);

        // A photo page the server decodes again (the teacher uploaded the photo anew) starts over too.
        $this->travel(31)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        $this->assertNull($page->refresh()->file_path);
        $this->assertSame($ids, $this->upload([UploadedFile::fake()->createWithContent('exam.jpg', self::pagePhoto())]));
        ExamFigures::cropPage($page->id);
        $this->assertNotNull($page->refresh()->file_path);
        $this->travel(1)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        $this->assertNotNull($page->refresh()->file_path);
    }

    public function test_the_teacher_draws_a_new_box_and_the_server_crops_it_again(): void
    {
        [$exam] = $this->importPhoto();
        $page = ExamPageImage::query()->sole();
        $q1 = Question::query()->where('assignment_id', $exam->id)->where('position', 1)->sole();

        $this->asUser($this->teacher)->putJson("/api/v1/questions/{$q1->id}/figure", ['page_image_id' => $page->id, 'box_2d' => [0, 0, 1000, 1000]])
            ->assertOk()
            ->assertJsonPath('data.figure_source.box_2d', [0, 0, 1000, 1000])
            ->assertJsonPath('data.figure_pending', false);
        $crop = imagecreatefromstring((string) ExamImages::disk()->get($q1->refresh()->prompt_image_path));
        $this->assertSame([1000, 800], [imagesx($crop), imagesy($crop)], 'the whole page, capped at 1,600 px');

        $option = QuestionOption::query()->whereNotNull('figure_source')->sole();
        $this->asUser($this->teacher)->putJson("/api/v1/question-options/{$option->id}/figure", ['page_image_id' => $page->id, 'box_2d' => [100, 100, 500, 600]])
            ->assertOk()
            ->assertJsonPath('data.options.1.figure_source.box_2d', [100, 100, 500, 600]);

        foreach ([[500, 100, 100, 600], [0, 0, 2, 1000], [0, 0, 1000], 'x'] as $box) {
            $this->asUser($this->teacher)->putJson("/api/v1/questions/{$q1->id}/figure", ['page_image_id' => $page->id, 'box_2d' => $box])
                ->assertStatus(422)->assertJsonValidationErrors('box_2d');
        }
        // A page image of another exam of the same teacher is not this exam's.
        $other = $this->createExam(['title' => 'สอบปลายภาค']);
        $foreign = ExamPageImage::create(['school_id' => $exam->school_id, 'assignment_id' => $other->id, 'source_document_id' => null, 'page_no' => 1, 'width_px' => 10, 'height_px' => 10]);
        $this->asUser($this->teacher)->putJson("/api/v1/questions/{$q1->id}/figure", ['page_image_id' => $foreign->id, 'box_2d' => [0, 0, 500, 500]])
            ->assertStatus(422)->assertJsonValidationErrors('page_image_id');

        // Once the page image is purged, a new box cannot be cropped.
        $this->travel(31)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        $this->assertNull($page->refresh()->file_path);
        $this->asUser($this->teacher)->putJson("/api/v1/questions/{$q1->id}/figure", ['page_image_id' => $page->id, 'box_2d' => [0, 0, 500, 500]])
            ->assertStatus(422)->assertJsonPath('code', 'document_missing');
        $this->asUser($this->teacher)->get("/api/v1/exam-page-images/{$page->id}")->assertNotFound()->assertJsonPath('code', 'document_missing');
        $this->assertTrue(ExamImages::disk()->exists($q1->refresh()->prompt_image_path), 'cropped figures stay with the exam');
    }

    public function test_a_pdf_page_range_waits_for_the_pages_the_app_renders(): void
    {
        $exam = $this->createExam();
        $ids = $this->upload([self::pdf(3)]);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import", ['document_ids' => $ids, 'page_from' => 2, 'page_to' => 3])->assertStatus(202);

        // Page 1 of what was sent is page 2 of the file; the last page sent is page 3.
        $q1 = Question::query()->where('assignment_id', $exam->id)->where('position', 1)->sole();
        $this->assertSame(['source_document_id' => $ids[0], 'page_no' => 2, 'box_2d' => [100, 100, 500, 600], 'page_image_id' => null], $q1->figure_source);
        $this->assertNull($q1->prompt_image_path);
        $json = $this->examJson($exam);
        $this->assertSame([[2, 1, 'needs_render'], [3, 1, 'needs_render']], array_map(fn ($p) => [$p['page_no'], $p['figures'], $p['reason']], $json['figures_pending']));
        $this->assertSame('application/pdf', $json['figures_pending'][0]['mime_type']);
        $this->assertTrue($json['sections'][0]['questions'][0]['figure_pending']);
        $this->assertTrue($json['sections'][0]['questions'][1]['options'][1]['figure_pending']);
        $this->assertSame(0, ExamPageImage::query()->count(), 'the server never renders a PDF');

        // The app downloads the file it read (only through this exam) and uploads the rendered page.
        $this->asUser($this->teacher)->get("/api/v1/exams/{$exam->id}/documents/{$ids[0]}/file")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $response = $this->asUser($this->teacher)->post("/api/v1/exams/{$exam->id}/page-images", [
            'source_document_id' => $ids[0], 'page_no' => 2, 'image' => self::renderedPage(),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.page_image.page_no', 2)
            ->assertJsonPath('data.page_image.width_px', 700)
            ->assertJsonPath('data.page_image.available', true);
        $this->assertSame([3], array_column($response->json('data.figures_pending'), 'page_no'));
        $q1->refresh();
        $this->assertNotNull($q1->prompt_image_path);
        $this->assertSame($response->json('data.page_image.id'), $q1->figure_source['page_image_id']);
        $blue = imagecolorsforindex($crop = imagecreatefromstring((string) ExamImages::disk()->get($q1->prompt_image_path)), imagecolorat($crop, 5, 5));
        $this->assertGreaterThan(150, $blue['blue']);

        // The same page again replaces the image; bad uploads are refused.
        $this->asUser($this->teacher)->post("/api/v1/exams/{$exam->id}/page-images", ['source_document_id' => $ids[0], 'page_no' => 2, 'image' => self::renderedPage()], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(1, ExamPageImage::query()->where('page_no', 2)->count());
        $this->asUser($this->teacher)->post("/api/v1/exams/{$exam->id}/page-images", ['source_document_id' => $ids[0], 'page_no' => 4, 'image' => self::renderedPage()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('page_no');
        $this->asUser($this->teacher)->post("/api/v1/exams/{$exam->id}/page-images", ['source_document_id' => $ids[0], 'page_no' => 3, 'image' => UploadedFile::fake()->image('page.png', 10, 10)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('image');
        $unrelated = $this->upload([self::pdf(1, 'other.pdf')]);
        $this->asUser($this->teacher)->post("/api/v1/exams/{$exam->id}/page-images", ['source_document_id' => $unrelated[0], 'page_no' => 1, 'image' => self::renderedPage()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('source_document_id');

        // A page image whose file was purged leaves its uncropped figures pending.
        ExamPageImage::create([
            'school_id' => $exam->school_id, 'assignment_id' => $exam->id, 'source_document_id' => $ids[0],
            'page_no' => 3, 'width_px' => 700, 'height_px' => 1000, 'uploaded_by' => $this->teacher->id,
        ]);
        $this->assertSame([3], array_column($this->examJson($exam)['figures_pending'], 'page_no'));

        // A page larger than the app renders is kept at 2,000 px on the long side.
        $big = imagecreatetruecolor(4000, 2000);
        imagefill($big, 0, 0, (int) imagecolorallocate($big, 0, 0, 220));
        ob_start();
        imagejpeg($big, null, 80);
        $this->asUser($this->teacher)->post("/api/v1/exams/{$exam->id}/page-images", [
            'source_document_id' => $ids[0], 'page_no' => 3, 'image' => UploadedFile::fake()->createWithContent('big.jpg', (string) ob_get_clean()),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.page_image.width_px', 2000)
            ->assertJsonPath('data.page_image.height_px', 1000)
            ->assertJsonPath('data.figures_pending', []);
        $stored = ExamPageImage::query()->where('page_no', 3)->sole();
        $this->assertSame([2000, 1000], array_slice((array) getimagesizefromstring((string) ExamImages::disk()->get($stored->file_path)), 0, 2));
    }

    public function test_the_source_file_is_only_for_the_owner_of_an_exam_that_read_it(): void
    {
        $exam = $this->createExam();
        $ids = $this->upload([self::pdf(1)]);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import", ['document_ids' => $ids])->assertStatus(202);
        $this->asUser($this->teacher)->get("/api/v1/exams/{$exam->id}/documents/{$ids[0]}/file")->assertOk();

        // A colleague of the same school guesses the id: a 404 through their own exam and through this one.
        $colleague = $this->makeTeacher($this->teacher->school);
        $theirClassroom = $this->makeClassroom($colleague);
        $theirCourse = $this->makeCourse($colleague, [$theirClassroom]);
        $theirs = Assignment::query()->findOrFail($this->asUser($colleague)->postJson('/api/v1/assignments', [
            'classroom_id' => $theirClassroom->id, 'course_id' => $theirCourse->id, 'title' => 'สอบย่อย', 'kind' => 'exam', 'due_at' => '2026-10-15T02:00:00Z',
        ])->assertCreated()->json('data.id'));
        $this->asUser($colleague)->get("/api/v1/exams/{$theirs->id}/documents/{$ids[0]}/file")->assertNotFound();
        $this->asUser($colleague)->get("/api/v1/exams/{$exam->id}/documents/{$ids[0]}/file")->assertNotFound();

        // Nor can they read the guessed id into their own exam first to unlock it: the id is not theirs.
        $this->asUser($colleague)->postJson("/api/v1/exams/{$theirs->id}/import/estimate", ['document_ids' => $ids])
            ->assertStatus(422)->assertJsonValidationErrors('document_ids');
        $this->asUser($colleague)->postJson("/api/v1/exams/{$theirs->id}/import", ['document_ids' => $ids])
            ->assertStatus(422)->assertJsonValidationErrors('document_ids');
        $this->assertSame(0, ExamImport::query()->where('assignment_id', $theirs->id)->count());
        $this->asUser($colleague)->get("/api/v1/exams/{$theirs->id}/documents/{$ids[0]}/file")->assertNotFound();
        $this->asUser($colleague)->post("/api/v1/exams/{$theirs->id}/page-images", ['source_document_id' => $ids[0], 'page_no' => 1, 'image' => self::renderedPage()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('source_document_id');

        // An import row that names a colleague's file (made before this rule) unlocks nothing either.
        ExamImport::create([
            'assignment_id' => $theirs->id, 'extraction_id' => ExamImport::query()->firstOrFail()->extraction_id,
            'documents' => [['source_document_id' => $ids[0], 'page_from' => 1, 'page_to' => 1]], 'requested_by' => $colleague->id,
        ]);
        $this->asUser($colleague)->get("/api/v1/exams/{$theirs->id}/documents/{$ids[0]}/file")->assertNotFound();
        $this->assertSame([], ExamFigures::importedDocumentIds($theirs));

        // Uploading the same bytes gives the colleague a row of their own that shares the stored file.
        $bytes = (string) SourceDocuments::disk()->get(SourceDocument::query()->findOrFail($ids[0])->file_path);
        $same = $this->upload([UploadedFile::fake()->createWithContent('exam.pdf', $bytes)], $colleague);
        $this->assertNotSame($ids[0], $same[0]);
        $mine = SourceDocument::query()->findOrFail($ids[0]);
        $copy = SourceDocument::query()->findOrFail($same[0]);
        $this->assertSame([$mine->sha256, $mine->file_path, $colleague->id], [$copy->sha256, $copy->file_path, $copy->uploaded_by]);
        $this->asUser($colleague)->postJson("/api/v1/exams/{$theirs->id}/import", ['document_ids' => $same])->assertOk()->assertJsonPath('data.cached', true);
        $this->asUser($colleague)->get("/api/v1/exams/{$theirs->id}/documents/{$same[0]}/file")->assertOk();

        // Another document of the school, not read into this exam: 404 as well.
        $other = $this->upload([self::pdf(2, 'plan.pdf')], $colleague);
        $this->asUser($this->teacher)->get("/api/v1/exams/{$exam->id}/documents/{$other[0]}/file")->assertNotFound();

        // After the 30 days the file is gone.
        $this->travel(31)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        $this->asUser($this->teacher)->get("/api/v1/exams/{$exam->id}/documents/{$ids[0]}/file")->assertNotFound()->assertJsonPath('code', 'document_missing');
        $this->assertSame(ExamFigures::DOCUMENT_MISSING, $this->examJson($exam)['figures_pending'][0]['reason']);
    }

    public function test_a_shared_file_stays_until_the_newest_upload_of_it_is_due(): void
    {
        $bytes = self::pagePhoto();
        $ids = $this->upload([UploadedFile::fake()->createWithContent('exam.jpg', $bytes)]);
        $this->travel(20)->days();
        $colleague = $this->makeTeacher($this->teacher->school);
        $theirs = $this->upload([UploadedFile::fake()->createWithContent('exam.jpg', $bytes)], $colleague);
        $path = SourceDocument::query()->findOrFail($theirs[0])->file_path;

        // Day 31: the first row is purged, the file stays for the colleague's row (day 20).
        $this->travel(11)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        $this->assertNull(SourceDocument::query()->findOrFail($ids[0])->file_path);
        $this->assertSame($path, SourceDocument::query()->findOrFail($theirs[0])->file_path);
        SourceDocuments::disk()->assertExists($path);

        // The first teacher uploads it again: their row comes back on the same file.
        $again = $this->upload([UploadedFile::fake()->createWithContent('exam.jpg', $bytes)]);
        $this->assertSame($ids, $again);
        $this->assertSame($path, SourceDocument::query()->findOrFail($ids[0])->file_path);

        // Day 51: the colleague's row is due, but the first teacher's revived row (day 31) keeps the file.
        $this->travel(20)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        $this->assertNull(SourceDocument::query()->findOrFail($theirs[0])->file_path);
        SourceDocuments::disk()->assertExists($path);

        // Day 62: nobody's row holds it any more.
        $this->travel(11)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        SourceDocuments::disk()->assertMissing($path);
    }

    public function test_the_school_reads_a_file_once_and_a_colleague_gets_it_applied_at_once(): void
    {
        $bytes = self::pagePhoto();
        [$exam] = $this->importPhoto();
        $this->assertCount(1, $this->sent());
        $extractionId = ExamImport::query()->sole()->extraction_id;

        // The read holds the owner's questions and answers: a colleague who guesses its id gets 404 (§22.17).
        $colleague = $this->makeTeacher($this->teacher->school);
        $this->asUser($colleague)->getJson("/api/v1/document-extractions/{$extractionId}")->assertNotFound();
        $classroom = $this->makeClassroom($colleague);
        $course = $this->makeCourse($colleague, [$classroom]);
        $theirs = Assignment::query()->findOrFail($this->asUser($colleague)->postJson('/api/v1/assignments', [
            'classroom_id' => $classroom->id, 'course_id' => $course->id, 'title' => 'สอบย่อย', 'kind' => 'exam', 'due_at' => '2026-10-15T02:00:00Z',
        ])->assertCreated()->json('data.id'));
        $again = $this->upload([UploadedFile::fake()->createWithContent('same.jpg', $bytes)], $colleague);
        $this->asUser($colleague)->postJson("/api/v1/exams/{$theirs->id}/import/estimate", ['document_ids' => $again])->assertOk()->assertJsonPath('data.cached', true);

        config(['services.gemini.api_key' => '']); // a cached read needs no key
        $this->asUser($colleague)->postJson("/api/v1/exams/{$theirs->id}/import", ['document_ids' => $again])
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.estimate', null)
            ->assertJsonPath('data.applied.sections', 3)
            ->assertJsonPath('data.applied.questions', 7)
            ->assertJsonPath('data.applied.skipped.0.number', 8)
            ->assertJsonPath('data.figures_pending', []);
        $this->assertCount(1, $this->sent(), 'no second Gemini call');
        // Having read the same file into their own exam, they may poll it.
        $this->asUser($colleague)->getJson("/api/v1/document-extractions/{$extractionId}")->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame(1, Question::query()->where('assignment_id', $theirs->id)->whereNotNull('prompt_image_path')->count(), 'figures cropped from their own page image');
        $this->assertSame(1, ExamPageImage::query()->where('assignment_id', $theirs->id)->count());

        // Other guidance is another read, and without any key it cannot be made.
        $this->asUser($colleague)->postJson("/api/v1/exams/{$theirs->id}/import", ['document_ids' => $again, 'guidance' => 'ข้อ 1–5 อยู่หน้าแรก'])
            ->assertStatus(422)->assertJsonPath('code', 'ai_key_missing');

        // Another school reads it again.
        config(['services.gemini.api_key' => 'testing-server-gemini-key-not-real']);
        $stranger = $this->makeTeacher();
        $classroom = $this->makeClassroom($stranger);
        $course = $this->makeCourse($stranger, [$classroom]);
        $far = $this->asUser($stranger)->postJson('/api/v1/assignments', [
            'classroom_id' => $classroom->id, 'course_id' => $course->id, 'title' => 'สอบ', 'kind' => 'exam', 'due_at' => '2026-10-15T02:00:00Z',
        ])->assertCreated()->json('data.id');
        $ids = $this->upload([UploadedFile::fake()->createWithContent('exam.jpg', $bytes)], $stranger);
        $this->asUser($stranger)->postJson("/api/v1/exams/{$far}/import", ['document_ids' => $ids, 'guidance' => 'ตอนที่ 3 เป็นเติมตัวเลข'])->assertStatus(202);
        $this->assertCount(2, $this->sent());
        $this->assertStringContainsString('ตอนที่ 3 เป็นเติมตัวเลข', $this->sent()[1]->userText);
        $this->assertSame(2, DocumentExtraction::query()->where('purpose', 'exam')->count());
    }

    public function test_questions_past_the_exam_limits_are_skipped_and_reported(): void
    {
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 100]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 60]);
        $ids = $this->upload([UploadedFile::fake()->createWithContent('many.jpg', "\xFF\xD8\xFF\xE0JFIF [fake:many]")]);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import", ['document_ids' => $ids])->assertStatus(202);
        $this->assertSame(200, Question::query()->where('assignment_id', $exam->id)->count());

        // A second exam reads the cached file: 40 fit after 160 blanks; 110 are reported.
        $second = $this->createExam(['title' => 'สอบซ่อม']);
        $this->addSection($second, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 100]);
        $this->addSection($second, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 60]);
        $applied = $this->asUser($this->teacher)->postJson("/api/v1/exams/{$second->id}/import", ['document_ids' => $ids])
            ->assertOk()
            ->assertJsonPath('data.applied.questions', 40)
            ->json('data.applied');
        $this->assertCount(110, $applied['skipped']);
        $this->assertSame(41, $applied['skipped'][0]['number']);
        $this->assertSame('ข้อสอบมีจำนวนข้อหรือจำนวนตอนครบตามที่กำหนดแล้ว', $applied['skipped'][0]['reason_th']);
    }

    public function test_a_printed_exam_cannot_import_and_a_failed_read_writes_nothing(): void
    {
        $exam = $this->createExam();
        $ids = $this->upload([UploadedFile::fake()->createWithContent('bad.jpg', "\xFF\xD8\xFF\xE0JFIF [fake:invalid]")]);
        $queued = $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import", ['document_ids' => $ids])->assertStatus(202);
        $this->asUser($this->teacher)->getJson('/api/v1/document-extractions/'.$queued->json('data.extraction.id'))
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.result', null);
        $this->assertSame(0, Question::query()->where('assignment_id', $exam->id)->count());
        $this->assertNull(ExamImport::query()->sole()->applied_at);

        $empty = $this->upload([UploadedFile::fake()->createWithContent('empty.jpg', "\xFF\xD8\xFF\xE0JFIF [fake:empty]")]);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import", ['document_ids' => $empty])->assertStatus(202);
        $this->assertSame(DocumentExtraction::STATUS_FAILED, DocumentExtraction::query()->latest('id')->first()->status);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import", [])->assertStatus(422)->assertJsonValidationErrors('document_ids');
        $long = $this->upload([self::pdf(31)]);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import", ['document_ids' => $long])->assertStatus(422)->assertJsonPath('code', 'document_too_long');

        $exam->forceFill(['structure_locked_at' => now()])->save();
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/import", ['document_ids' => $ids])->assertStatus(409)->assertJsonPath('code', 'exam_structure_locked');
    }

    public function test_a_read_that_finishes_after_the_exam_was_printed_is_not_applied(): void
    {
        $exam = $this->createExam();
        $ids = $this->upload([UploadedFile::fake()->createWithContent('exam.jpg', self::pagePhoto())]);
        $document = SourceDocument::query()->findOrFail($ids[0]);
        $extraction = DocumentExtraction::create([
            'school_id' => $exam->school_id, 'input_hash' => $document->sha256, 'purpose' => 'exam',
            'status' => DocumentExtraction::STATUS_QUEUED, 'requested_by' => $this->teacher->id,
        ]);
        $import = ExamImport::create(['assignment_id' => $exam->id, 'extraction_id' => $extraction->id, 'documents' => [['source_document_id' => $document->id, 'page_from' => 1, 'page_to' => 1]], 'requested_by' => $this->teacher->id]);
        $exam->forceFill(['structure_locked_at' => now()])->save();

        app(ExamDocuments::class)->process($extraction->id, $ids, null, null, $this->teacher->id, $exam->id, lastAttempt: true);

        $this->assertTrue($extraction->refresh()->isDone(), 'the school keeps the read');
        $this->assertNull($import->refresh()->applied_at);
        $this->assertSame(0, Question::query()->where('assignment_id', $exam->id)->count());
    }
}
