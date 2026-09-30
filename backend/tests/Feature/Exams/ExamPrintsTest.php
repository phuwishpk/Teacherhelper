<?php

namespace Tests\Feature\Exams;

use App\Domain\Worksheets\PdfMerger;
use App\Jobs\MergeWorksheetsJob;
use App\Jobs\RenderAnswerSheetsJob;
use App\Jobs\RenderExamBookletJob;
use App\Models\Assignment;
use App\Models\Layout;
use App\Models\Question;
use App\Models\WorksheetPrint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §22.6, §22.15, §22.16: POST /exams/{id}/prints queues a booklet of
 * one version, the classroom's answer sheets in chunks of 20 plus a merge,
 * or the teacher's key sheet, on the pdf queue; the first print locks the
 * structure; sheets share one layout per layout_version.
 */
class ExamPrintsTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['eduvision.qr_signing_key' => 'testing-qr-signing-key-not-a-secret']);
        $this->makeExamWorld();
    }

    /** @param array<string, mixed> $body */
    private function print(Assignment $exam, array $body): TestResponse
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", $body);
    }

    private function enroll(int $count): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $this->enrollStudent($this->classroom, $n, 'นักเรียนคนที่ '.$n);
        }
    }

    private function pdfPages(int $printId): int
    {
        $print = WorksheetPrint::query()->findOrFail($printId);

        return PdfMerger::pageCount(Storage::disk('local')->path($print->file_path));
    }

    /** An app exam with an approved key: $mcq bubble rows and $numeric digit blocks. */
    private function approvedExam(int $mcq = 10, int $numeric = 0, int $versions = 1): Assignment
    {
        $exam = $this->createExam(['version_count' => $versions]);
        while ($mcq > 0) {
            $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => min(100, $mcq)]);
            $mcq -= 100;
        }
        if ($numeric > 0) {
            $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 2], 'question_count' => $numeric]);
        }

        return $this->approveExam($exam);
    }

    public function test_answer_sheets_are_queued_in_chunks_of_twenty_and_merged_on_the_pdf_queue(): void
    {
        $exam = $this->approvedExam(versions: 2);
        $this->enroll(45);
        Queue::fake();

        $id = $this->print($exam, ['kind' => 'answer_sheet'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.kind', 'answer_sheet')
            ->assertJsonPath('data.layout_version', 1)
            ->assertJsonPath('data.version_no', null)
            ->json('data.id');

        Queue::assertPushedOn('pdf', RenderAnswerSheetsJob::class, fn (RenderAnswerSheetsJob $job) => $job->printId === $id && $job->batch === 0 && count($job->studentIds) === 20);
        Queue::assertPushedWithChain(RenderAnswerSheetsJob::class, [
            RenderAnswerSheetsJob::class,
            RenderAnswerSheetsJob::class,
            MergeWorksheetsJob::class,
        ]);

        // The first print locks the structure and stores the layout the phone reads.
        $exam->refresh();
        $this->assertNotNull($exam->structure_locked_at);
        $this->assertSame(1, $exam->current_layout_version);
        $layout = $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$exam->id}/layouts?version=1")
            ->assertOk()->assertJsonPath('data.page_count', 1)->json('data.pages.0');
        $this->assertSame('exam', $layout['sheet']);
        $this->assertSame(['version', ...array_map(fn ($n) => "s{$n}", range(1, 10))], array_column($layout['regions'], 'region_id'));
    }

    public function test_the_answer_sheets_have_every_page_for_every_student_and_download(): void
    {
        $exam = $this->approvedExam(mcq: 110, numeric: 2);
        $this->enroll(3);

        $id = $this->print($exam, ['kind' => 'answer_sheet'])->assertStatus(202)->assertJsonPath('data.status', 'ready')->json('data.id');

        $this->assertSame(2, count($exam->refresh()->currentLayout()->pages));
        $this->assertSame(3 * 2, $this->pdfPages($id));
        $download = $this->asUser($this->teacher)->get("/api/v1/worksheet-prints/{$id}/file")->assertOk();
        $this->assertStringContainsString("exam-{$exam->id}-answer-sheets-v1.pdf", (string) $download->headers->get('Content-Disposition'));
    }

    public function test_chosen_students_only_and_they_must_be_in_the_classroom(): void
    {
        $exam = $this->approvedExam();
        $this->enroll(3);
        $ids = $this->classroom->students()->pluck('users.id')->all();
        $stranger = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1)['student'];

        $this->print($exam, ['kind' => 'answer_sheet', 'student_ids' => [$ids[0], $stranger->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['student_ids.1']);
        $this->assertSame(0, WorksheetPrint::query()->count());
        $this->assertNull($exam->refresh()->structure_locked_at, 'a refused print does not lock');

        $id = $this->print($exam, ['kind' => 'answer_sheet', 'student_ids' => [$ids[2]]])->assertStatus(202)->json('data.id');
        $this->assertSame(1, $this->pdfPages($id));
    }

    public function test_an_empty_classroom_has_no_answer_sheets(): void
    {
        $exam = $this->approvedExam();

        $this->print($exam, ['kind' => 'answer_sheet'])->assertStatus(422)->assertJsonPath('code', 'classroom_empty');
    }

    public function test_a_booklet_is_one_file_per_version(): void
    {
        $exam = $this->approvedExam(versions: 3);
        Queue::fake();

        $this->print($exam, ['kind' => 'exam_booklet'])->assertStatus(422)->assertJsonValidationErrors(['version_no']);
        $this->print($exam, ['kind' => 'exam_booklet', 'version_no' => 4])->assertStatus(422)->assertJsonValidationErrors(['version_no']);
        $this->print($exam, ['kind' => 'nothing'])->assertStatus(422)->assertJsonValidationErrors(['kind']);

        $id = $this->print($exam, ['kind' => 'exam_booklet', 'version_no' => 2])
            ->assertStatus(202)
            ->assertJsonPath('data.kind', 'exam_booklet')
            ->assertJsonPath('data.version_no', 2)
            ->assertJsonPath('data.layout_version', null)
            ->json('data.id');
        Queue::assertPushedOn('pdf', RenderExamBookletJob::class, fn (RenderExamBookletJob $job) => $job->printId === $id);
        $this->assertNotNull($exam->refresh()->structure_locked_at);
        $this->assertNull($exam->current_layout_version, 'a booklet has no layout');
    }

    public function test_a_rendered_booklet_downloads(): void
    {
        $exam = $this->approvedExam(mcq: 30);

        $id = $this->print($exam, ['kind' => 'exam_booklet'])->assertStatus(202)
            ->assertJsonPath('data.status', 'ready')->assertJsonPath('data.version_no', 1)->json('data.id');

        $this->assertGreaterThanOrEqual(1, $this->pdfPages($id));
        $download = $this->asUser($this->teacher)->get("/api/v1/worksheet-prints/{$id}/file")->assertOk();
        $this->assertStringContainsString("exam-{$exam->id}-booklet-1.pdf", (string) $download->headers->get('Content-Disposition'));
    }

    public function test_the_key_sheet_prints_before_approval_and_locks_the_structure(): void
    {
        $exam = $this->createExam(['version_count' => 2]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 5]);

        // Nothing to print for students yet.
        $this->print($exam, ['kind' => 'answer_sheet'])->assertStatus(409)->assertJsonPath('code', 'answer_key_not_approved');
        $this->print($exam, ['kind' => 'exam_booklet', 'version_no' => 1])->assertStatus(409)->assertJsonPath('code', 'answer_key_not_approved');
        $this->assertNull($exam->refresh()->structure_locked_at);

        $id = $this->print($exam, ['kind' => 'key_sheet'])
            ->assertStatus(202)->assertJsonPath('data.kind', 'key_sheet')->assertJsonPath('data.status', 'ready')->json('data.id');
        $this->assertSame(1, $this->pdfPages($id));
        $this->assertNotNull($exam->refresh()->structure_locked_at);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/sections", ['type' => 'true_false'])
            ->assertStatus(409)->assertJsonPath('code', 'exam_structure_locked');
        $download = $this->asUser($this->teacher)->get("/api/v1/worksheet-prints/{$id}/file")->assertOk();
        $this->assertStringContainsString("exam-{$exam->id}-key-sheet-v1.pdf", (string) $download->headers->get('Content-Disposition'));

        $empty = $this->createExam();
        $this->print($empty, ['kind' => 'key_sheet'])->assertStatus(422)->assertJsonPath('code', 'assignment_empty');
    }

    public function test_sheets_share_a_layout_version_until_the_structure_is_unlocked(): void
    {
        $exam = $this->approvedExam(versions: 2);
        $this->enroll(1);

        $this->print($exam, ['kind' => 'key_sheet'])->assertStatus(202)->assertJsonPath('data.layout_version', 1);
        $this->print($exam, ['kind' => 'answer_sheet'])->assertStatus(202)->assertJsonPath('data.layout_version', 1);
        $this->assertSame(1, Layout::query()->where('assignment_id', $exam->id)->count());

        // Unlocking reshuffles the versions: the next print is a new layout version even with the same grid.
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/unlock-structure")->assertOk();
        $this->assertNull($exam->refresh()->current_layout_version);
        $this->print($exam, ['kind' => 'key_sheet'])->assertStatus(202)->assertJsonPath('data.layout_version', 2);
        $this->assertSame(2, $exam->refresh()->current_layout_version);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$exam->id}/layouts")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_more_than_two_pages_is_refused(): void
    {
        $exam = $this->approvedExam(mcq: 0, numeric: 17);
        $this->enroll(1);

        $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}")->assertOk()->assertJsonPath('data.sheet', ['pages' => 3, 'overflow' => true]);
        $this->print($exam, ['kind' => 'answer_sheet'])->assertStatus(422)->assertJsonPath('code', 'exam_sheet_overflow');
        $this->print($exam, ['kind' => 'key_sheet'])->assertStatus(422)->assertJsonPath('code', 'exam_sheet_overflow');
        $this->assertNull($exam->refresh()->structure_locked_at);
        // The booklet has no answer grid, so it still prints.
        $this->print($exam, ['kind' => 'exam_booklet'])->assertStatus(202);
    }

    public function test_two_hundred_rows_print_on_two_pages(): void
    {
        $exam = $this->approvedExam(mcq: 200);
        Queue::fake();

        $this->print($exam, ['kind' => 'key_sheet'])->assertStatus(202);

        $pages = $exam->refresh()->currentLayout()->pages;
        $this->assertCount(2, $pages);
        $this->assertCount(100, $pages[0]['regions']);
        $this->assertSame('s101', $pages[1]['regions'][0]['region_id']);
    }

    public function test_a_booklet_needs_every_question_approved_with_a_prompt(): void
    {
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 3]);
        $this->fillExam($exam, prompts: false);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/answer-key/approve")->assertOk();
        $this->enroll(1);

        // The answer sheet needs only the key.
        $this->print($exam, ['kind' => 'answer_sheet'])->assertStatus(202);
        $this->print($exam, ['kind' => 'exam_booklet'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'answer_key_incomplete')
            ->assertJsonPath('errors.questions.0', 'ข้อ 1: ยังไม่มีโจทย์');
    }

    public function test_a_manual_exam_prints_only_its_booklet(): void
    {
        $exam = $this->createExam(['grading_method' => 'manual', 'manual_full_marks' => 50]);

        $this->print($exam, ['kind' => 'answer_sheet'])->assertStatus(422)->assertJsonPath('code', 'exam_manual_grading');
        $this->print($exam, ['kind' => 'key_sheet'])->assertStatus(422)->assertJsonPath('code', 'exam_manual_grading');
        $this->print($exam, ['kind' => 'exam_booklet'])->assertStatus(422)->assertJsonPath('code', 'answer_key_incomplete');

        $section = $this->addSection($exam, ['type' => 'true_false', 'question_count' => 2]);
        $this->print($exam, ['kind' => 'exam_booklet'])->assertStatus(422)->assertJsonPath('code', 'answer_key_incomplete')
            ->assertJsonPath('errors.questions.0', 'ข้อ 1: ยังไม่อนุมัติ, ยังไม่มีโจทย์');

        // A manual exam never needs a key: prompts approve its blank questions.
        foreach ($section['questions'] as $question) {
            $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$question['id']}", ['prompt_text' => 'ดวงอาทิตย์ขึ้นทางทิศตะวันออก'])->assertOk();
        }
        $this->print($exam, ['kind' => 'exam_booklet'])->assertStatus(202)->assertJsonPath('data.status', 'ready');
        $this->assertNull(Question::query()->where('assignment_id', $exam->id)->value('answer_key'));
    }

    public function test_sheets_need_the_qr_signing_key_but_the_booklet_does_not(): void
    {
        $exam = $this->approvedExam();
        $this->enroll(1);
        config(['eduvision.qr_signing_key' => '']);

        $this->print($exam, ['kind' => 'answer_sheet'])->assertStatus(503)->assertJsonPath('code', 'qr_key_missing');
        $this->print($exam, ['kind' => 'key_sheet'])->assertStatus(503)->assertJsonPath('code', 'qr_key_missing');
        $this->print($exam, ['kind' => 'exam_booklet'])->assertStatus(202);
    }

    public function test_homework_and_other_teachers_exams_are_not_found(): void
    {
        $exam = $this->approvedExam();
        $homework = Assignment::factory()->for_classroom($this->classroom)->create();

        $this->print($homework, ['kind' => 'exam_booklet'])->assertNotFound();
        $this->asUser($this->makeTeacher())->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'exam_booklet'])->assertNotFound();
        // The homework print route does not take exams (§22.3).
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/worksheets")
            ->assertStatus(422)->assertJsonPath('code', 'exam_kind_unsupported');
    }
}
