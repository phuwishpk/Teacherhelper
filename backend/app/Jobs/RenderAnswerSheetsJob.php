<?php

namespace App\Jobs;

use App\Domain\Exams\ExamSheetPdfRenderer;
use App\Domain\Worksheets\QrSigningKeyMissing;
use App\Domain\Worksheets\WorksheetFiles;
use App\Domain\Worksheets\WorksheetLayoutException;
use App\Domain\Worksheets\WorksheetStudent;
use App\Models\Assignment;
use App\Models\User;
use App\Models\WorksheetPrint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders one chunk of an exam's answer sheets (DESIGN §22.6 item 2: 20
 * students per job, eduvision.exams.sheet_batch_size) into a part file, or
 * the teacher's key sheet (item 3) for a key_sheet print; MergeWorksheetsJob
 * joins the parts after the last chunk in the same chain on the `pdf` queue.
 * A failure marks the print `failed` and the later jobs return early. The
 * render time is logged so it can be measured on the hosting (§5.5).
 */
class RenderAnswerSheetsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** eduvision:queue-work runs with --max-time=50 every minute. */
    public int $timeout = 45;

    /**
     * @param  list<int>  $studentIds  empty for a key sheet and for the shared sheet of §22.19
     */
    public function __construct(
        public readonly int $printId,
        public readonly int $batch,
        public readonly array $studentIds,
    ) {
        $this->onQueue('pdf');
    }

    public function handle(ExamSheetPdfRenderer $renderer): void
    {
        $print = WorksheetPrint::query()->find($this->printId);
        if ($print === null || $print->isFinished()) {
            return;
        }
        if ($print->status === WorksheetPrint::STATUS_QUEUED) {
            $print->update(['status' => WorksheetPrint::STATUS_RENDERING]);
        }

        try {
            $started = hrtime(true);
            $exam = Assignment::query()->with(['classroom', 'course', 'subject'])->findOrFail($print->assignment_id);
            $layout = $exam->layouts()->where('version', $print->layout_version)->firstOrFail();
            $students = match (true) {
                $print->kind === WorksheetPrint::KIND_KEY_SHEET => [null],
                // One shared sheet for the whole class (DESIGN §22.19).
                $exam->usesCodeSheets() => [ExamSheetPdfRenderer::SHARED_SHEET],
                default => $this->students($exam),
            };
            if ($students === []) {
                return; // everyone in this chunk left the classroom; the merge skips a missing part
            }

            $pdf = $renderer->render($exam, $layout, $students);
            Storage::disk('local')->put(WorksheetFiles::part($print, $this->batch), $pdf);

            Log::info('exams.render_answer_sheets', [
                'print_id' => $print->id,
                'kind' => $print->kind,
                'batch' => $this->batch,
                'students' => count($students),
                'pages' => count($students) * count($layout->pages),
                'ms' => (int) ((hrtime(true) - $started) / 1e6),
            ]);
        } catch (WorksheetLayoutException $e) {
            $print->markFailed($e->getMessage());
        } catch (Throwable $e) {
            $print->markFailed('สร้าง PDF กระดาษคำตอบไม่สำเร็จ กรุณาลองใหม่');
            report($e);
        }
    }

    /** The worker killed the job, or the renderer could not be built (QR_SIGNING_KEY removed after queueing). */
    public function failed(?Throwable $e): void
    {
        $print = WorksheetPrint::query()->find($this->printId);
        if ($print !== null && ! $print->isFinished()) {
            $print->markFailed($e instanceof QrSigningKeyMissing
                ? QrSigningKeyMissing::USER_MESSAGE
                : 'สร้าง PDF กระดาษคำตอบไม่ทันเวลา กรุณาลองใหม่');
        }
    }

    /**
     * Students of this chunk still enrolled in the classroom, by เลขที่.
     *
     * @return list<WorksheetStudent>
     */
    private function students(Assignment $exam): array
    {
        return $exam->classroom->students()
            ->whereIn('users.id', $this->studentIds)
            ->get()
            ->map(fn (User $s) => new WorksheetStudent($s->id, $s->name, (int) $s->pivot->student_number))
            ->values()
            ->all();
    }
}
