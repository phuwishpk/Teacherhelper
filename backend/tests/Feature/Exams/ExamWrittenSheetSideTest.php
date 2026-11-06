<?php

namespace Tests\Feature\Exams;

use App\Domain\Exams\ExamSheetLayout;
use App\Domain\Worksheets\PdfMerger;
use App\Domain\Worksheets\QrSigner;
use App\Jobs\MergeWorksheetsJob;
use App\Jobs\RenderAnswerSheetsJob;
use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\ExamVersion;
use App\Models\Question;
use App\Models\Submission;
use App\Models\WorksheetPrint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DESIGN §22.20.3 and the step-1 guard of §22.20.9: written questions are
 * not bubble rows, not shuffled and not in the key by sheet number; until
 * step 2 gives them pages, the answer sheet and scan-kit of an exam that
 * has them answer 422 exam_written_not_ready.
 */
class ExamWrittenSheetSideTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    private Assignment $exam;

    /** @var list<int> */
    private array $writtenIds;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['eduvision.qr_signing_key' => 'testing-qr-signing-key-not-a-secret']);
        $this->makeExamWorld();
        $this->exam = $this->createExam(['version_count' => 3]);
        $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 6]);
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 2]);
        $this->writtenIds = array_column($open['questions'], 'id');
        $this->exam = $this->approveExam($this->exam);
    }

    public function test_versions_do_not_shuffle_or_key_written_questions(): void
    {
        $data = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$this->exam->id}/versions")->assertOk()->json('data');

        $this->assertCount(3, $data['versions']);
        foreach ($data['versions'] as $version) {
            $this->assertSame($this->writtenIds, array_slice($version['question_order'], -2), "version {$version['version_no']} keeps the written order");
            $this->assertSame([1, 2, 3, 4, 5, 6], array_column($version['items'], 'sheet_no'));
            $this->assertSame([], array_intersect($this->writtenIds, array_column($version['items'], 'question_id')));
        }
        $this->assertCount(3, ExamVersion::query()->where('assignment_id', $this->exam->id)->get());
    }

    public function test_bubble_layout_ignores_written_questions(): void
    {
        $items = ExamSheetLayout::items($this->exam);

        $this->assertCount(6, $items);
        foreach ($items as $item) {
            $this->assertContains($item->type, ExamSection::TYPES);
        }
    }

    public function test_only_the_scan_kit_is_still_refused_for_written_exams(): void
    {
        Queue::fake();
        $this->enroll(3);
        $url = "/api/v1/exams/{$this->exam->id}/prints";

        $this->asUser($this->teacher)->getJson("/api/v1/exams/{$this->exam->id}/scan-kit")
            ->assertStatus(422)->assertJsonPath('code', 'exam_written_not_ready')
            ->assertJsonPath('message', 'ยังพิมพ์กระดาษคำตอบหรือเตรียมสแกนข้อสอบที่มีข้อเขียนไม่ได้ (กำลังพัฒนา)');

        $this->asUser($this->teacher)->postJson($url, ['kind' => 'answer_sheet'])->assertStatus(202);
        $this->asUser($this->teacher)->postJson($url, ['kind' => 'exam_booklet', 'version_no' => 1])->assertStatus(202);
        $this->asUser($this->teacher)->postJson($url, ['kind' => 'key_sheet'])->assertStatus(202);
    }

    public function test_a_written_exam_prints_every_page_of_every_student_in_one_pdf(): void
    {
        $this->enroll(3);

        $id = $this->asUser($this->teacher)->postJson("/api/v1/exams/{$this->exam->id}/prints", ['kind' => 'answer_sheet'])
            ->assertStatus(202)->json('data.id');

        $print = WorksheetPrint::query()->findOrFail($id);
        $this->assertSame('ready', $print->status);
        $this->assertSame(6, PdfMerger::pageCount(Storage::disk('local')->path($print->file_path)), '3 students x (1 bubble page + 1 written page)');
        $layout = $this->exam->refresh()->currentLayout();
        $this->assertSame($layout->version, $print->layout_version);
        $this->assertSame(['exam', 'written'], array_column($layout->pages, 'sheet'));
        $this->assertNotNull($this->exam->structure_locked_at);
    }

    public function test_the_key_sheet_of_a_written_exam_is_one_bubble_page(): void
    {
        $id = $this->asUser($this->teacher)->postJson("/api/v1/exams/{$this->exam->id}/prints", ['kind' => 'key_sheet'])
            ->assertStatus(202)->json('data.id');

        $print = WorksheetPrint::query()->findOrFail($id);
        $this->assertSame(1, PdfMerger::pageCount(Storage::disk('local')->path($print->file_path)));
    }

    public function test_a_written_exam_renders_ten_students_to_a_job_and_a_bubble_exam_twenty(): void
    {
        Bus::fake();
        $this->enroll(25);
        $url = "/api/v1/exams/{$this->exam->id}/prints";

        $this->asUser($this->teacher)->postJson($url, ['kind' => 'answer_sheet'])->assertStatus(202);
        Bus::assertChained([RenderAnswerSheetsJob::class, RenderAnswerSheetsJob::class, RenderAnswerSheetsJob::class, MergeWorksheetsJob::class]);

        $bubbles = $this->createExam(['title' => 'สอบย่อย']);
        $this->addSection($bubbles, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 6]);
        $this->approveExam($bubbles);
        Bus::fake();
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$bubbles->id}/prints", ['kind' => 'answer_sheet'])->assertStatus(202);
        Bus::assertChained([RenderAnswerSheetsJob::class, RenderAnswerSheetsJob::class, MergeWorksheetsJob::class]);
    }

    private function enroll(int $count): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $this->enrollStudent($this->classroom, $n, 'นักเรียนคนที่ '.$n);
        }
    }

    public function test_the_copier_skips_written_questions_and_the_library_hides_them(): void
    {
        $target = $this->createExam(['title' => 'สอบย่อย']);
        $section = $this->addSection($target, ['type' => 'open']);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => $this->writtenIds])
            ->assertCreated()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped.0.reason', 'written_not_copyable');
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => $this->writtenIds, 'section_id' => $section['id']])
            ->assertCreated()->assertJsonPath('data.created', 0);
        $this->assertSame(0, Question::query()->where('section_id', $section['id'])->count());
        $this->assertSame(1, ExamSection::query()->where('assignment_id', $target->id)->count(), 'no section was created for them');

        $listed = $this->asUser($this->teacher)->getJson('/api/v1/teacher/exam-questions')->assertOk()->json('data');
        $this->assertSame([], array_intersect($this->writtenIds, array_column($listed, 'id')));
        $this->assertCount(6, $listed);
    }

    public function test_an_essay_only_exam_has_no_key_sheet_and_stays_unlocked(): void
    {
        Queue::fake();
        $essay = $this->createExam(['title' => 'สอบเขียนล้วน']);
        $this->addSection($essay, ['type' => 'open', 'question_count' => 2]);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$essay->id}/prints", ['kind' => 'key_sheet'])
            ->assertStatus(422)->assertJsonPath('code', 'assignment_empty')
            ->assertJsonPath('message', 'ข้อสอบนี้ไม่มีข้อฝน จึงไม่มีกระดาษเฉลย (เฉลยของข้อเขียนคือ rubric)');

        $this->assertNull($essay->refresh()->structure_locked_at, 'a refused print does not lock the structure');
        $this->assertSame(0, $essay->worksheetPrints()->count());
    }

    public function test_the_homework_scan_route_refuses_the_pages_of_an_exam(): void
    {
        $student = $this->enrollStudent($this->classroom, 1, 'นักเรียนที่สแกน')['student'];
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$this->exam->id}/prints", ['kind' => 'answer_sheet'])->assertStatus(202);
        $version = (int) $this->exam->refresh()->current_layout_version;
        $questionId = $this->writtenIds[0];
        $meta = [
            'client_scan_id' => (string) Str::uuid(),
            'qr' => app(QrSigner::class)->sign($this->exam->id, $student->id, 2, $version),
            'scanned_at' => '2026-11-07T09:15:00+07:00',
            'blur_score' => 180.0,
            'regions' => [['region_id' => "q{$questionId}", 'question_id' => $questionId, 'file' => "crop_q{$questionId}", 'ink_ratio' => 0.2]],
        ];
        $webp = fn () => UploadedFile::fake()->createWithContent('page.webp', (string) file_get_contents(base_path('tests/fixtures/scans/page.webp')));

        $this->asUser($this->teacher)->post('/api/v1/scans', ['meta' => json_encode($meta), 'page' => $webp(), "crop_q{$questionId}" => $webp()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'exam_written_not_ready');

        $this->assertSame(0, Submission::query()->where('assignment_id', $this->exam->id)->count(), 'no submission was filed');
        $this->assertSame(0, Question::query()->findOrFail($questionId)->responses()->count());
    }
}
