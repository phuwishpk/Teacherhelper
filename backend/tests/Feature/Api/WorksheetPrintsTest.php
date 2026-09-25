<?php

namespace Tests\Feature\Api;

use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Worksheets\PdfMerger;
use App\Domain\Worksheets\WorksheetFiles;
use App\Domain\Worksheets\WorksheetPdfRenderer;
use App\Jobs\MergeWorksheetsJob;
use App\Jobs\RenderWorksheetsJob;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Question;
use App\Models\User;
use App\Models\WorksheetPrint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §5.5, §9.3: POST /assignments/{id}/worksheets -> RenderWorksheetsJob
 * batches -> MergeWorksheetsJob -> GET /worksheet-prints/{id} (+ /file).
 */
class WorksheetPrintsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @return array{User, Assignment, Classroom}
     */
    private function readyAssignment(int $students = 3, int $questions = 3): array
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher, ['name' => 'ป.5/2']);
        if ($students > 0) {
            app(StudentEnroller::class)->enroll($classroom, array_map(
                fn (int $n) => ['name' => 'นักเรียนคนที่ '.$n, 'student_number' => $n],
                range(1, $students),
            ));
        }
        $assignment = Assignment::factory()->for_classroom($classroom)->create(['title' => 'เศษส่วน ชุดที่ 3']);
        $factories = [
            fn () => Question::factory(),
            fn () => Question::factory()->short(),
            fn () => Question::factory()->showWork(lines: 5),
            fn () => Question::factory()->open(lines: 6),
        ];
        for ($i = 0; $i < $questions; $i++) {
            $factories[$i % 4]()->create(['assignment_id' => $assignment->id]);
        }

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")->assertStatus(201);

        return [$teacher, $assignment->refresh(), $classroom];
    }

    private function pagesPerSheet(Assignment $assignment): int
    {
        return count($assignment->currentLayout()->pages);
    }

    public function test_the_print_is_queued_as_a_chain_of_batches_and_a_merge_on_the_pdf_queue(): void
    {
        config(['eduvision.worksheets.batch_size' => 2]);
        [$teacher, $assignment] = $this->readyAssignment(students: 5);
        Queue::fake();

        $response = $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/worksheets")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.assignment_id', $assignment->id)
            ->assertJsonPath('data.layout_version', 1)
            ->assertJsonPath('data.download_url', null);
        $id = $response->json('data.id');
        $this->assertSame("/api/v1/worksheet-prints/{$id}", $response->json('data.status_url'));

        Queue::assertPushedOn('pdf', RenderWorksheetsJob::class, fn (RenderWorksheetsJob $job) => $job->printId === $id && $job->batch === 0 && count($job->studentIds) === 2);
        Queue::assertPushedWithChain(RenderWorksheetsJob::class, [
            RenderWorksheetsJob::class,
            RenderWorksheetsJob::class,
            MergeWorksheetsJob::class,
        ]);
    }

    public function test_the_rendered_pdf_has_every_page_for_every_student_and_can_be_downloaded(): void
    {
        config(['eduvision.worksheets.batch_size' => 2]);
        [$teacher, $assignment] = $this->readyAssignment(students: 5, questions: 6);

        // QUEUE_CONNECTION=sync: the whole chain runs inline.
        $id = $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/worksheets")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'ready')
            ->json('data.id');

        $this->asUser($teacher)->getJson("/api/v1/worksheet-prints/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.download_url', "/api/v1/worksheet-prints/{$id}/file");

        $print = WorksheetPrint::findOrFail($id);
        $this->assertSame(WorksheetFiles::final($print), $print->file_path);
        $this->assertSame("worksheets/{$assignment->id}/{$id}.pdf", $print->file_path);
        Storage::disk('local')->assertMissing(WorksheetFiles::partsDirectory($print));

        $pdfPath = Storage::disk('local')->path($print->file_path);
        $this->assertSame(5 * $this->pagesPerSheet($assignment), PdfMerger::pageCount($pdfPath));

        $download = $this->asUser($teacher)->get("/api/v1/worksheet-prints/{$id}/file")->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertStringContainsString("worksheets-{$assignment->id}-v1.pdf", (string) $download->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $download->streamedContent());
    }

    public function test_the_assignment_must_be_ready(): void
    {
        [$teacher, $assignment] = $this->readyAssignment();
        $assignment->update(['status' => 'draft']);

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/worksheets")
            ->assertStatus(409)->assertJsonPath('code', 'assignment_not_ready');
        $this->assertSame(0, WorksheetPrint::query()->count());
    }

    public function test_an_empty_classroom_cannot_print(): void
    {
        [$teacher, $assignment] = $this->readyAssignment(students: 0);

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/worksheets")
            ->assertStatus(422)->assertJsonPath('code', 'classroom_empty');
    }

    public function test_an_edit_after_queueing_fails_the_print_with_a_reason(): void
    {
        [$teacher, $assignment] = $this->readyAssignment(students: 2);
        Queue::fake();
        $id = $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/worksheets")->json('data.id');

        // The teacher changes the page before the worker picks the job up.
        $question = $assignment->questions()->where('type', 'show_work')->firstOrFail();
        $this->asUser($teacher)->patchJson("/api/v1/questions/{$question->id}", ['answer_lines' => 8])->assertOk();

        $students = $assignment->classroom->students()->pluck('users.id')->map(fn ($v) => (int) $v)->all();
        (new RenderWorksheetsJob($id, 0, $students))->handle(app(WorksheetPdfRenderer::class));
        (new MergeWorksheetsJob($id, 1))->handle(app(PdfMerger::class));

        $this->asUser($teacher)->getJson("/api/v1/worksheet-prints/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.download_url', null)
            ->assertJsonPath('data.error', 'การบ้านถูกแก้ไขหลังสร้าง layout เวอร์ชัน 1 กรุณาสร้าง layout ใหม่แล้วสั่งพิมพ์อีกครั้ง');
        $this->asUser($teacher)->get("/api/v1/worksheet-prints/{$id}/file")->assertStatus(409)->assertJsonPath('code', 'print_not_ready');
    }

    public function test_a_worker_timeout_marks_the_print_failed(): void
    {
        [$teacher, $assignment] = $this->readyAssignment(students: 1);
        $print = WorksheetPrint::create(['assignment_id' => $assignment->id, 'layout_version' => 1, 'requested_by' => $teacher->id, 'status' => 'rendering']);

        (new RenderWorksheetsJob($print->id, 0, []))->failed(new \RuntimeException('timeout'));

        $this->assertSame('failed', $print->refresh()->status);
        $this->assertSame('สร้าง PDF ใบงานไม่ทันเวลา กรุณาลองใหม่', $print->error);
    }

    public function test_only_the_teacher_of_the_classroom_can_poll_and_download(): void
    {
        [$teacher, $assignment] = $this->readyAssignment(students: 1);
        $id = $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/worksheets")->json('data.id');
        $colleague = $this->makeTeacher($teacher->school);
        $outsider = $this->makeTeacher();
        $student = $assignment->classroom->students()->first();

        $this->asUser($colleague)->getJson("/api/v1/worksheet-prints/{$id}")->assertForbidden();
        $this->asUser($colleague)->get("/api/v1/worksheet-prints/{$id}/file")->assertForbidden();
        $this->asUser($outsider)->getJson("/api/v1/worksheet-prints/{$id}")->assertNotFound();
        $this->asUser($outsider)->postJson("/api/v1/assignments/{$assignment->id}/worksheets")->assertNotFound();
        $this->asUser($student)->getJson("/api/v1/worksheet-prints/{$id}")->assertForbidden();
        $this->asGuest()->getJson("/api/v1/worksheet-prints/{$id}/file")->assertUnauthorized();
    }

    public function test_a_classroom_of_40_renders_within_the_worker_budget(): void
    {
        [$teacher, $assignment] = $this->readyAssignment(students: 40, questions: 8);
        $pages = $this->pagesPerSheet($assignment);

        $started = hrtime(true);
        $id = $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/worksheets")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'ready')
            ->json('data.id');
        $ms = (hrtime(true) - $started) / 1e6;

        $path = Storage::disk('local')->path(WorksheetPrint::findOrFail($id)->file_path);
        $this->assertSame(40 * $pages, PdfMerger::pageCount($path));
        fwrite(STDERR, sprintf(
            "\n[worksheets] 40 students x %d pages = %d pages rendered in 4 batches + merge: %.0f ms (%.1f KB)\n",
            $pages, 40 * $pages, $ms, filesize($path) / 1024,
        ));
        // Four 10-student batches plus a merge each have 45 s on the hosting;
        // locally the whole chain must be far below one worker pass.
        $this->assertLessThan(45_000, $ms);
    }
}
