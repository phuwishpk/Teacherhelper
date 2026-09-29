<?php

namespace Tests\Feature\Api;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Pages\PdfPageCounter;
use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\DocumentExtraction;
use App\Models\Question;
use App\Models\SourceDocument;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mpdf\Mpdf;
use Tests\TestCase;

/**
 * DESIGN §19.5, §21.2, §21.6: the teacher's answer key of a freeform (or any)
 * assignment. Documents are uploaded once, read once by Gemini
 * (answer_key_read, thinking medium) into per-question keys, cached by
 * SHA-256 for the whole school, or drafted by AI when the teacher has no key
 * (answer_key_draft, key_origin ai_draft). Nothing is graded before the
 * teacher approves the key. Gemini is the FakeGeminiClient.
 */
class AnswerKeyTest extends TestCase
{
    use RefreshDatabase;

    private FakeGeminiClient $gemini;

    private User $teacher;

    private Classroom $classroom;

    private Assignment $assignment;

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

        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher, ['grade_level' => 5]);
        $this->assignment = $this->freeform($this->classroom);
    }

    private function freeform(Classroom $classroom): Assignment
    {
        return Assignment::factory()->for_classroom($classroom)->freeform()->create([
            'subject_id' => Subject::factory()->create(['name' => 'คณิตศาสตร์'])->id,
        ]);
    }

    private static function photo(string $content = '', string $name = 'key.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xFF\xD8\xFF\xE0JFIF answer key ".$content.' '.bin2hex(random_bytes(4)));
    }

    private static function pdfBytes(int $pages): string
    {
        $mpdf = new Mpdf(['tempDir' => storage_path('framework/testing')]);
        for ($i = 1; $i <= $pages; $i++) {
            if ($i > 1) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML("<p>page {$i}</p>");
        }

        return $mpdf->Output('', 'S');
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function upload(array $files, ?User $teacher = null): TestResponse
    {
        return $this->asUser($teacher ?? $this->teacher)->post('/api/v1/documents', ['files' => $files], ['Accept' => 'application/json']);
    }

    /** Uploads the files and returns their ids. @param list<UploadedFile> $files @return list<int> */
    private function documents(array $files, ?User $teacher = null): array
    {
        return array_column($this->upload($files, $teacher)->assertCreated()->json('data'), 'id');
    }

    /** @return list<GeminiRequest> */
    private function sent(string $purpose): array
    {
        return array_values(array_filter($this->gemini->requests, fn (GeminiRequest $r) => $r->purpose === $purpose));
    }

    public function test_documents_are_stored_once_with_their_pages_and_a_cost_estimate(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('key.pdf', self::pdfBytes(3));
        $response = $this->upload([self::photo(), $pdf])->assertCreated();

        $photo = $response->json('data.0');
        $this->assertSame(['image/jpeg', 1, false, []], [$photo['mime_type'], $photo['page_count'], $photo['needs_page_range'], $photo['cached_purposes']]);
        // 1 page x 560 (PDF / photo at medium) + 1,500 prompt; ~5 questions x 150 out (§19.5).
        $this->assertSame(['input_tokens' => 2060, 'output_tokens' => 750, 'thb' => round((2060 * 0.5 + 750 * 3.0) / 1e6 * 33, 2)], $photo['estimate']);
        $this->assertSame(['application/pdf', 3, 3180], [$response->json('data.1.mime_type'), $response->json('data.1.page_count'), $response->json('data.1.estimate.input_tokens')]);

        $document = SourceDocument::query()->findOrFail($photo['id']);
        $this->assertSame("documents/{$this->teacher->school_id}/{$document->sha256}.jpg", $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);

        // Without prices in .env the estimate has tokens only.
        config(['services.gemini.price_input_per_m' => null]);
        $again = $this->upload([UploadedFile::fake()->createWithContent('same.jpg', (string) Storage::disk('local')->get($document->file_path))])->assertCreated();
        $this->assertSame($document->id, $again->json('data.0.id'), 'the same file in the same school reuses its row');
        $this->assertNull($again->json('data.0.estimate.thb'));
        $this->assertSame(2, SourceDocument::query()->count());
    }

    public function test_word_google_docs_and_other_files_are_refused(): void
    {
        foreach ([
            ['key.docx', 'PK word', 'บันทึกเป็น PDF แล้วแนบใหม่'],
            ['key.doc', 'word 97', 'บันทึกเป็น PDF แล้วแนบใหม่'],
            ['notes.txt', 'plain text', 'แนบเป็นรูป'],
        ] as [$name, $content, $message]) {
            $this->upload([self::photo(), UploadedFile::fake()->createWithContent($name, $content)])
                ->assertStatus(422)
                ->assertJsonPath('code', 'unsupported_file_type')
                ->assertJsonPath('errors', fn ($errors) => str_contains($errors['files.1'][0], $message));
        }
        $this->upload([UploadedFile::fake()->create('doc.gdoc', 1, 'application/vnd.google-apps.document')])
            ->assertStatus(422)->assertJsonPath('code', 'unsupported_file_type');

        config(['eduvision.documents.max_file_mb' => 1]);
        $this->upload([UploadedFile::fake()->createWithContent('big.jpg', "\xFF\xD8".str_repeat('x', 1024 * 1024 + 10))])
            ->assertStatus(422)->assertJsonPath('code', 'file_too_large');
        $this->upload([UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.7\n1 0 obj garbage")])
            ->assertStatus(422)->assertJsonPath('code', 'pdf_unreadable');
        $this->upload([])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->assertSame(0, SourceDocument::query()->count(), 'nothing is kept from a refused upload');
    }

    public function test_the_key_is_read_once_into_new_questions_of_a_freeform_assignment(): void
    {
        $ids = $this->documents([self::photo()]);

        $response = $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/extract", ['document_ids' => $ids])
            ->assertStatus(202)
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.estimate.input_tokens', 2060)
            ->assertJsonPath('data.answer_key.extraction_status', 'done')
            ->assertJsonPath('data.answer_key.key_origin', Assignment::KEY_DOCUMENT)
            ->assertJsonPath('data.answer_key.key_approved_at', null);

        // One Gemini read: the photo at the document resolution, thinking medium (§21.5, §21.6).
        $read = $this->sent('answer_key_read');
        $this->assertCount(1, $read);
        $this->assertSame(['image/jpeg', 'medium', 'medium', 16384], [$read[0]->images[0]->mimeType, $read[0]->images[0]->mediaResolution, $read[0]->thinkingLevel, $read[0]->maxOutputTokens]);
        $this->assertStringContainsString('SUBJECT: คณิตศาสตร์, GRADE: ป.5', $read[0]->userText);
        $this->assertStringNotContainsString($this->teacher->name, $read[0]->systemInstruction.$read[0]->userText);
        $call = AiCall::query()->where('purpose', 'answer_key_read')->sole();
        $this->assertSame(['key_from_document', 'medium', 1, $this->assignment->id, 'ok'], [$call->feature, $call->media_resolution, $call->image_count, $call->assignment_id, $call->status]);

        // The key became draft questions of the assignment.
        $questions = $this->assignment->questions()->get();
        $this->assertSame(['mcq', 'short', 'show_work', 'open'], $questions->pluck('type')->all());
        $this->assertSame([1, 2, 3, 4], $questions->pluck('position')->all());
        $this->assertSame(['correct' => 'C'], $questions[0]->answer_key);
        $this->assertEquals(['accepted' => ['42', 'สี่สิบสอง'], 'numeric' => ['value' => 42, 'abs_tol' => 0]], $questions[1]->answer_key);
        $this->assertTrue($questions[1]->is_numeric);
        $this->assertSame(['3 × 4', '= 12 บาท'], $questions[2]->answer_key['reference_steps']);
        $this->assertSame([Question::RUBRIC_DRAFT, 5.0, 5], [$questions[2]->rubric_status, $questions[2]->max_points, $questions[2]->answer_lines]);
        $this->assertStringContainsString('ประเด็นสำคัญ:', (string) $questions[3]->model_answer);

        // The open question's rubric is drafted from the teacher's model answer (§10.4).
        $rubric = $this->sent('rubric_draft');
        $this->assertCount(1, $rubric);
        $this->assertStringContainsString('คำตอบตัวอย่างของครู:', $rubric[0]->userText);
        $this->assertStringContainsString('ใบไม้มีคลอโรฟิลล์ซึ่งสะท้อนแสงสีเขียว', $rubric[0]->userText);
        $this->assertNotEmpty($questions[3]->rubricCriteria()->get());

        // GET shows the draft: not approved, show_work and open rubrics still to approve.
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/answer-key")
            ->assertOk()
            ->assertJsonPath('data.key_complete', false)
            ->assertJsonPath('data.incomplete_questions', [3, 4])
            ->assertJsonPath('data.extraction.kind', 'answer_key_read')
            ->assertJsonPath('data.questions.1.key_complete', true);
        $this->assertSame(Assignment::STATUS_DRAFT, $this->assignment->refresh()->status);
        $this->assertSame('done', $response->json('data.answer_key.extraction.status'));
    }

    public function test_the_same_file_is_read_once_per_school_and_every_teacher_gets_a_copy(): void
    {
        $file = self::photo();
        $bytes = (string) file_get_contents($file->getRealPath());
        $ids = $this->documents([$file]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/extract", ['document_ids' => $ids])->assertStatus(202);
        $this->assertCount(1, $this->sent('answer_key_read'));

        // A colleague uploads the same file: "read before, free" and filled at once.
        $colleague = $this->makeTeacher($this->teacher->school);
        $theirs = $this->freeform($this->makeClassroom($colleague, ['grade_level' => 5]));
        $upload = $this->upload([UploadedFile::fake()->createWithContent('scan.jpg', $bytes)], $colleague)->assertCreated();
        $this->assertSame(['answer_key'], $upload->json('data.0.cached_purposes'));
        $this->asUser($colleague)->postJson("/api/v1/assignments/{$theirs->id}/answer-key/extract", ['document_ids' => [$upload->json('data.0.id')]])
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.estimate', null)
            ->assertJsonPath('data.applied.created', 4)
            ->assertJsonPath('data.answer_key.questions.0.answer_key.correct', 'C');
        $this->assertCount(1, $this->sent('answer_key_read'), 'no second Gemini call');
        $this->assertSame(1, DocumentExtraction::query()->count());

        // Their own copy: an edit does not touch the other teacher's questions or the cache.
        $theirs->questions()->where('position', 1)->update(['answer_key' => json_encode(['correct' => 'D'])]);
        $this->assertSame(['correct' => 'C'], $this->assignment->questions()->where('position', 1)->sole()->answer_key);
        $this->assertSame('C', DocumentExtraction::query()->sole()->result['questions'][0]['answer_key']['correct']);

        // Another school reads it again.
        $other = $this->makeTeacher();
        $otherAssignment = $this->freeform($this->makeClassroom($other));
        $otherIds = $this->documents([UploadedFile::fake()->createWithContent('scan.jpg', $bytes)], $other);
        $this->assertNotSame($ids, $otherIds);
        $this->asUser($other)->postJson("/api/v1/assignments/{$otherAssignment->id}/answer-key/extract", ['document_ids' => $otherIds])
            ->assertStatus(202)
            ->assertJsonPath('data.cached', false);
        $this->assertCount(2, $this->sent('answer_key_read'));
        // A document of another school is not found.
        $this->asUser($other)->postJson("/api/v1/assignments/{$otherAssignment->id}/answer-key/extract", ['document_ids' => $ids])
            ->assertStatus(422)->assertJsonPath('errors.document_ids.0', 'ไม่พบไฟล์ที่เลือก');
        $this->asUser($other)->getJson('/api/v1/document-extractions/'.DocumentExtraction::query()->orderBy('id')->first()->id)->assertNotFound();
        $this->asUser($colleague)->getJson('/api/v1/document-extractions/'.DocumentExtraction::query()->orderBy('id')->first()->id)
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.result.kind', 'answer_key_read');
    }

    public function test_a_read_fills_the_existing_questions_by_position(): void
    {
        $mcq = Question::factory()->create(['assignment_id' => $this->assignment->id, 'answer_key' => null]);
        $short = Question::factory()->short(false)->create(['assignment_id' => $this->assignment->id, 'answer_key' => null]);
        $open = Question::factory()->open(Question::RUBRIC_DRAFT)->create(['assignment_id' => $this->assignment->id, 'prompt_text' => 'ทำไมใบไม้จึงมีสีเขียว']);
        $ids = $this->documents([self::photo()]);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/extract", ['document_ids' => $ids])
            ->assertStatus(202);

        $read = $this->sent('answer_key_read')[0];
        $this->assertSame([1, 2, 3], array_column($read->hints['questions'], 'question_no'), 'the app\'s questions go with the files');
        $this->assertStringContainsString('"type": "open"', $read->userText);
        $this->assertSame(['correct' => 'C'], $mcq->refresh()->answer_key);
        $this->assertSame(['42', 'สี่สิบสอง'], $short->refresh()->answer_key['accepted']);
        $this->assertSame([1.0, 2.0], [$mcq->max_points, $short->max_points], 'points are kept');
        $this->assertNotNull($open->refresh()->model_answer);
        $this->assertSame(3, $this->assignment->questions()->count(), 'no question added to an existing set');
    }

    public function test_a_long_document_needs_a_page_range(): void
    {
        $bytes = self::pdfBytes(32);
        $id = $this->documents([UploadedFile::fake()->createWithContent('book.pdf', $bytes)])[0];
        $url = "/api/v1/assignments/{$this->assignment->id}/answer-key/extract";

        $this->asUser($this->teacher)->postJson($url, ['document_ids' => [$id]])
            ->assertStatus(422)->assertJsonPath('code', 'document_too_long');
        $this->asUser($this->teacher)->postJson($url, ['document_ids' => [$id], 'page_from' => 1, 'page_to' => 31])
            ->assertStatus(422)->assertJsonPath('code', 'document_too_long');
        $this->asUser($this->teacher)->postJson($url, ['document_ids' => [$id], 'page_from' => 30, 'page_to' => 33])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        // Pages 3-7 only: cut on the server, 5 pages to Gemini, 5 x 560 + 1,500 tokens.
        $this->asUser($this->teacher)->postJson($url, ['document_ids' => [$id], 'page_from' => 3, 'page_to' => 7])
            ->assertStatus(202)
            ->assertJsonPath('data.estimate.input_tokens', 5 * 560 + 1500);
        $image = $this->sent('answer_key_read')[0]->images[0];
        $this->assertSame('application/pdf', $image->mimeType);
        $this->assertSame(5, PdfPageCounter::count($image->data));

        // Another range of the same file is another cache entry.
        $this->assertSame(1, DocumentExtraction::query()->count());
        $this->assertNotSame(SourceDocument::query()->sole()->sha256, DocumentExtraction::query()->sole()->input_hash);
    }

    public function test_the_estimate_of_a_picked_range_and_the_cache_are_shown_before_a_read(): void
    {
        $id = $this->documents([UploadedFile::fake()->createWithContent('book.pdf', self::pdfBytes(32))])[0];
        $url = "/api/v1/assignments/{$this->assignment->id}/answer-key/estimate";

        $this->asUser($this->teacher)->postJson($url, ['document_ids' => [$id]])
            ->assertStatus(422)->assertJsonPath('code', 'document_too_long');
        $this->asUser($this->teacher)->postJson($url, ['kind' => 'other', 'document_ids' => [$id]])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        // Pages 3-7: 5 x 560 + 1,500 in, 5 pages x 5 questions x 150 out, in baht from the .env prices.
        $this->asUser($this->teacher)->postJson($url, ['document_ids' => [$id], 'page_from' => 3, 'page_to' => 7])
            ->assertOk()
            ->assertJsonPath('data.kind', 'answer_key_read')
            ->assertJsonPath('data.pages', 5)
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.estimate', ['input_tokens' => 4300, 'output_tokens' => 3750, 'thb' => round((4300 * 0.5 + 3750 * 3.0) / 1e6 * 33, 2)]);
        $this->assertSame([], $this->gemini->requests, 'an estimate never calls Gemini');
        $this->assertSame(0, DocumentExtraction::query()->count());

        // After the read, the same range is free; another range is not.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/extract", ['document_ids' => [$id], 'page_from' => 3, 'page_to' => 7])
            ->assertStatus(202);
        $this->asUser($this->teacher)->postJson($url, ['document_ids' => [$id], 'page_from' => 3, 'page_to' => 7])
            ->assertOk()->assertJsonPath('data.cached', true);
        $this->asUser($this->teacher)->postJson($url, ['document_ids' => [$id], 'page_from' => 3, 'page_to' => 8])
            ->assertOk()->assertJsonPath('data.cached', false);

        // A draft hashes the questions too: not the read's cache entry; outputs follow the question count.
        $this->asUser($this->teacher)->postJson($url, ['kind' => 'draft'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'answer_key_draft')
            ->assertJsonPath('data.pages', 0)
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.estimate.output_tokens', $this->assignment->questions()->count() * 150);

        // Only the owner may ask.
        $this->asUser($this->makeTeacher())->postJson($url, ['document_ids' => [$id], 'page_from' => 3, 'page_to' => 7])->assertNotFound();
    }

    public function test_a_range_of_a_pdf_with_a_cross_reference_stream_cannot_be_cut(): void
    {
        config(['eduvision.documents.max_pages' => 1]);
        $id = $this->documents([UploadedFile::fake()->createWithContent('word.pdf', self::xrefStreamPdf(2))])[0];

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/extract", ['document_ids' => [$id], 'page_from' => 1, 'page_to' => 1])
            ->assertStatus(422)
            ->assertJsonPath('code', 'document_split_unsupported');
        $this->assertSame([], $this->sent('answer_key_read'));
        $this->assertSame(0, DocumentExtraction::query()->count());
    }

    public function test_without_a_teacher_key_ai_drafts_the_answers(): void
    {
        $url = "/api/v1/assignments/{$this->assignment->id}/answer-key/draft";
        $this->asUser($this->teacher)->postJson($url)->assertStatus(422)->assertJsonPath('code', 'assignment_empty');

        // Typed questions without answers (allowed in a freeform assignment).
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/questions", ['type' => 'short', 'prompt_text' => '6 × 7 เท่ากับเท่าไร', 'max_points' => 2])
            ->assertCreated()->assertJsonPath('data.answer_key', null)->assertJsonPath('data.key_complete', false);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/questions", ['type' => 'mcq', 'prompt_text' => 'ข้อใดเป็นจำนวนเฉพาะ', 'max_points' => 1])
            ->assertCreated();

        $this->asUser($this->teacher)->postJson($url)
            ->assertStatus(202)
            ->assertJsonPath('data.answer_key.key_origin', Assignment::KEY_AI_DRAFT)
            ->assertJsonPath('data.answer_key.key_complete', true)
            ->assertJsonPath('data.answer_key.extraction.kind', 'answer_key_draft');

        $draft = $this->sent('answer_key_draft');
        $this->assertCount(1, $draft);
        $this->assertSame([[], 'medium', 4096], [$draft[0]->images, $draft[0]->thinkingLevel, $draft[0]->maxOutputTokens]);
        $this->assertStringContainsString('6 × 7 เท่ากับเท่าไร', $draft[0]->userText);
        $this->assertSame('key_ai_draft', AiCall::query()->where('purpose', 'answer_key_draft')->sole()->feature);
        $this->assertSame(['42', 'สี่สิบสอง'], $this->assignment->questions()->where('position', 1)->sole()->answer_key['accepted']);

        // The AI draft is labelled until approved; approval keeps the origin.
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}")->assertJsonPath('data.key_origin', 'ai_draft');
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/approve")
            ->assertOk()->assertJsonPath('data.key_origin', 'ai_draft')->assertJsonPath('data.status', 'ready');

        // A question sheet as a file: read and answered in one call.
        $other = $this->freeform($this->classroom);
        $ids = $this->documents([self::photo('questions only')]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$other->id}/answer-key/draft", ['document_ids' => $ids])->assertStatus(202);
        $this->assertSame(4, $other->questions()->count());
        $this->assertStringContainsString('show the question sheet', $this->sent('answer_key_draft')[1]->userText);
    }

    public function test_a_failed_read_is_reported_and_asked_again(): void
    {
        $ids = $this->documents([self::photo('[fake:invalid]')]);
        $url = "/api/v1/assignments/{$this->assignment->id}/answer-key/extract";

        $this->asUser($this->teacher)->postJson($url, ['document_ids' => $ids])
            ->assertStatus(202)
            ->assertJsonPath('data.answer_key.extraction_status', 'failed')
            ->assertJsonPath('data.answer_key.extraction.error', 'AI อ่านเฉลยไม่สำเร็จ ลองถ่ายรูปให้ชัดขึ้น แนบไฟล์ใหม่ หรือพิมพ์เฉลยเอง');
        $this->assertSame(['invalid_output', 'invalid_output'], AiCall::query()->pluck('status')->all(), 'one retry of invalid output');
        $this->assertSame(0, $this->assignment->questions()->count());

        // Asking again resets the failed entry and reads once more.
        $this->asUser($this->teacher)->postJson($url, ['document_ids' => $ids])->assertStatus(202);
        $this->assertSame(1, DocumentExtraction::query()->count());
        $this->assertCount(4, $this->sent('answer_key_read'));

        // A document without any question is invalid output as well.
        $empty = $this->documents([self::photo('[fake:empty]')]);
        $this->asUser($this->teacher)->postJson($url, ['document_ids' => $empty])
            ->assertJsonPath('data.answer_key.extraction_status', 'failed');
    }

    public function test_a_read_needs_a_gemini_key_but_a_cached_one_does_not(): void
    {
        $file = self::photo();
        $bytes = (string) file_get_contents($file->getRealPath());
        $ids = $this->documents([$file]);
        config(['services.gemini.api_key' => '']);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/extract", ['document_ids' => $ids])
            ->assertStatus(422)->assertJsonPath('code', 'ai_key_missing');
        $this->assertSame(0, DocumentExtraction::query()->count());

        // Read with a key by a colleague, then free for this teacher without one.
        config(['services.gemini.api_key' => 'testing-server-gemini-key-not-real']);
        $colleague = $this->makeTeacher($this->teacher->school);
        $theirs = $this->freeform($this->makeClassroom($colleague));
        $this->asUser($colleague)->postJson("/api/v1/assignments/{$theirs->id}/answer-key/extract", ['document_ids' => $this->documents([UploadedFile::fake()->createWithContent('a.jpg', $bytes)], $colleague)])->assertStatus(202);
        config(['services.gemini.api_key' => '']);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/extract", ['document_ids' => $ids])
            ->assertOk()->assertJsonPath('data.cached', true);
    }

    public function test_approval_needs_a_complete_key_and_makes_a_freeform_assignment_ready(): void
    {
        $url = "/api/v1/assignments/{$this->assignment->id}/answer-key/approve";
        $this->asUser($this->teacher)->postJson($url)->assertStatus(422)->assertJsonPath('code', 'assignment_empty');

        Question::factory()->create(['assignment_id' => $this->assignment->id]);
        Question::factory()->short()->create(['assignment_id' => $this->assignment->id, 'answer_key' => null]);
        Question::factory()->open(Question::RUBRIC_DRAFT)->create(['assignment_id' => $this->assignment->id]);
        $this->asUser($this->teacher)->postJson($url)
            ->assertStatus(422)
            ->assertJsonPath('code', 'answer_key_incomplete')
            ->assertJsonPath('errors.questions', ['ข้อ 2 ยังไม่มีเฉลยหรือ rubric ที่อนุมัติแล้ว', 'ข้อ 3 ยังไม่มีเฉลยหรือ rubric ที่อนุมัติแล้ว']);

        $this->assignment->questions()->where('position', 2)->update(['answer_key' => json_encode(['accepted' => ['20']])]);
        $this->assignment->questions()->where('position', 3)->update(['rubric_status' => Question::RUBRIC_APPROVED]);
        $this->asUser($this->teacher)->postJson($url)
            ->assertOk()
            ->assertJsonPath('data.status', Assignment::STATUS_READY)
            ->assertJsonPath('data.key_origin', Assignment::KEY_TEACHER)
            ->assertJsonPath('data.key_approved_by', $this->teacher->id)
            ->assertJsonPath('data.key_complete', true);
        $this->assertNotNull($this->assignment->refresh()->key_approved_at);

        // A worksheet's approval does not make it ready (its layout does).
        $worksheet = Assignment::factory()->for_classroom($this->classroom)->create();
        Question::factory()->create(['assignment_id' => $worksheet->id]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$worksheet->id}/answer-key/approve")
            ->assertOk()->assertJsonPath('data.status', Assignment::STATUS_DRAFT);

        // Closed: nothing changes.
        $this->assignment->update(['status' => Assignment::STATUS_CLOSED]);
        $this->asUser($this->teacher)->postJson($url)->assertStatus(409)->assertJsonPath('code', 'assignment_closed');
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/draft")->assertStatus(409);
    }

    public function test_a_ready_freeform_assignment_stays_ready_while_its_key_is_complete(): void
    {
        Question::factory()->create(['assignment_id' => $this->assignment->id]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/approve")->assertOk();
        $base = "/api/v1/assignments/{$this->assignment->id}/questions";

        // A complete question: still ready (a freeform assignment has no printed page).
        $this->asUser($this->teacher)->postJson($base, ['type' => 'short', 'prompt_text' => '2 + 2', 'max_points' => 1, 'answer_key' => ['accepted' => ['4']]])->assertCreated();
        $this->assertSame([Assignment::STATUS_READY, true], [$this->assignment->refresh()->status, $this->assignment->keyApproved()]);

        // A question without a key: back to draft and the approval is cleared (ready ⇔ approved).
        $this->asUser($this->teacher)->postJson($base, ['type' => 'open', 'prompt_text' => 'อธิบาย', 'max_points' => 2, 'answer_lines' => 3, 'model_answer' => 'คำตอบตัวอย่าง'])
            ->assertCreated()->assertJsonPath('data.model_answer', 'คำตอบตัวอย่าง');
        $this->assertSame([Assignment::STATUS_DRAFT, false], [$this->assignment->refresh()->status, $this->assignment->keyApproved()]);

        // A worksheet still needs its key typed with the question.
        $worksheet = Assignment::factory()->for_classroom($this->classroom)->create();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$worksheet->id}/questions", ['type' => 'short', 'prompt_text' => '2 + 2', 'max_points' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('answer_key.accepted');
    }

    public function test_mode_score_only_and_late_policy_on_create_and_update(): void
    {
        $created = $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->assignment->subject_id,
            'title' => 'งานจากหนังสือเรียน',
            'mode' => 'freeform',
            'score_only' => true,
            'accept_late' => false,
        ])->assertCreated()
            ->assertJsonPath('data.mode', 'freeform')
            ->assertJsonPath('data.source', 'app')
            ->assertJsonPath('data.score_only', true)
            ->assertJsonPath('data.accept_late', false)
            ->assertJsonPath('data.key_approved_at', null);
        $id = $created->json('data.id');
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', ['classroom_id' => $this->classroom->id, 'subject_id' => $this->assignment->subject_id, 'title' => 'x', 'mode' => 'paper'])
            ->assertStatus(422)->assertJsonValidationErrors('mode');

        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['mode' => 'worksheet', 'score_only' => false])
            ->assertOk()->assertJsonPath('data.mode', 'worksheet')->assertJsonPath('data.score_only', false);

        // Once there is a layout (or a submission) the mode is fixed.
        $worksheet = Assignment::query()->findOrFail($id);
        Question::factory()->create(['assignment_id' => $id]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$id}/layout")->assertCreated();
        $this->assertNotNull($worksheet->refresh()->key_approved_at, 'building the layout approves a worksheet key');
        $this->assertSame($this->teacher->id, $worksheet->key_approved_by);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['status' => 'draft'])->assertOk();
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['mode' => 'freeform'])
            ->assertStatus(422)->assertJsonValidationErrors('mode');

        // A freeform assignment has no worksheet; sending it back to draft clears its approval.
        Question::factory()->create(['assignment_id' => $this->assignment->id]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/layout")
            ->assertStatus(422)->assertJsonPath('code', 'assignment_freeform');
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/approve")->assertOk();
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$this->assignment->id}", ['status' => 'draft'])
            ->assertOk()->assertJsonPath('data.key_approved_at', null);
    }

    public function test_a_worksheet_layout_needs_a_complete_key(): void
    {
        $worksheet = Assignment::factory()->for_classroom($this->classroom)->freeform()->create();
        Question::factory()->short()->create(['assignment_id' => $worksheet->id, 'answer_key' => null]);
        $worksheet->update(['mode' => Assignment::MODE_WORKSHEET]);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$worksheet->id}/layout")
            ->assertStatus(422)
            ->assertJsonPath('code', 'answer_key_incomplete');
    }

    public function test_documents_are_deleted_after_the_retention_but_the_read_stays(): void
    {
        $ids = $this->documents([self::photo()]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/extract", ['document_ids' => $ids])->assertStatus(202);
        $document = SourceDocument::query()->sole();
        $path = (string) $document->file_path;

        $this->artisan('eduvision:purge-images')->assertSuccessful();
        Storage::disk('local')->assertExists($path);

        $this->travel(31)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($document->refresh()->file_path);
        $this->assertSame(DocumentExtraction::STATUS_DONE, DocumentExtraction::query()->sole()->status);

        // The read is still free; a new read of a purged file asks for it again.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/extract", ['document_ids' => $ids])
            ->assertOk()->assertJsonPath('data.cached', true);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/draft", ['document_ids' => $ids])
            ->assertStatus(422)->assertJsonPath('code', 'document_missing');
    }

    /** A PDF 1.5 whose pages sit in an object stream behind a cross-reference stream (as in PdfPageCounterTest). */
    private static function xrefStreamPdf(int $pages): string
    {
        $objects = ['<< /Type /Catalog /Pages 3 0 R >>'];
        $kids = implode(' ', array_map(fn (int $i) => (4 + $i).' 0 R', range(0, $pages - 1)));
        $objects[] = "<< /Type /Pages /Kids [{$kids}] /Count {$pages} >>";
        for ($i = 0; $i < $pages; $i++) {
            $objects[] = '<< /Type /Page /Parent 3 0 R /MediaBox [0 0 595 842] >>';
        }
        $header = '';
        $body = '';
        foreach ($objects as $i => $object) {
            $header .= (2 + $i).' '.strlen($body).' ';
            $body .= $object."\n";
        }
        $stream = gzcompress($header.$body);

        $pdf = "%PDF-1.5\n";
        $pdf .= '1 0 obj << /Type /ObjStm /N '.count($objects).' /First '.strlen($header).' /Filter /FlateDecode /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream\nendobj\n";
        $xrefAt = strlen($pdf);
        $xref = gzcompress(str_repeat("\x01\x00\x00\x00", 3));
        $pdf .= '9 0 obj << /Type /XRef /Size 10 /W [1 2 1] /Root 2 0 R /Filter /FlateDecode /Length '.strlen($xref)." >>\nstream\n{$xref}\nendstream\nendobj\n";

        return $pdf."startxref\n{$xrefAt}\n%%EOF\n";
    }
}
