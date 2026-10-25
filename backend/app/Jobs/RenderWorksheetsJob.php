<?php

namespace App\Jobs;

use App\Domain\Worksheets\QrSigningKeyMissing;
use App\Domain\Worksheets\WorksheetFiles;
use App\Domain\Worksheets\WorksheetLayoutException;
use App\Domain\Worksheets\WorksheetPdfRenderer;
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
 * Renders one batch of students (DESIGN §5.5: 10 per job) of a worksheet
 * print into a part file; MergeWorksheetsJob runs after the last batch in the
 * same chain. A failure marks the print `failed` and the later jobs of the
 * chain return early. The render time is logged so it can be measured on the
 * hosting (DESIGN §5.5, Phase 1 exit criterion).
 */
class RenderWorksheetsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** eduvision:queue-work runs with --max-time=50 every minute. */
    public int $timeout = 45;

    /**
     * @param  list<int>  $studentIds
     */
    public function __construct(
        public readonly int $printId,
        public readonly int $batch,
        public readonly array $studentIds,
    ) {
        $this->onQueue('pdf');
    }

    public function handle(WorksheetPdfRenderer $renderer): void
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
            $assignment = Assignment::query()->with(['classroom', 'subject', 'questions'])->findOrFail($print->assignment_id);
            $layout = $assignment->layouts()->where('version', $print->layout_version)->firstOrFail();
            $students = $this->students($assignment);
            if ($students === []) {
                return; // everyone in this batch left the classroom; the merge skips a missing part
            }

            $pdf = $renderer->render($assignment, $layout, $students);
            Storage::disk('local')->put(WorksheetFiles::part($print, $this->batch), $pdf);

            Log::info('worksheets.render_batch', [
                'print_id' => $print->id,
                'batch' => $this->batch,
                'students' => count($students),
                'pages' => count($students) * count($layout->pages),
                'ms' => (int) ((hrtime(true) - $started) / 1e6),
            ]);
        } catch (WorksheetLayoutException $e) {
            $print->markFailed($e->getMessage());
        } catch (Throwable $e) {
            $print->markFailed('สร้าง PDF ใบงานไม่สำเร็จ กรุณาลองใหม่');
            report($e);
        }
    }

    /**
     * The job failed outside handle()'s own catch: the worker killed it
     * (timeout), or resolving the renderer threw before handle() ran, which
     * happens when QR_SIGNING_KEY was removed after the print was queued.
     */
    public function failed(?Throwable $e): void
    {
        $print = WorksheetPrint::query()->find($this->printId);
        if ($print !== null && ! $print->isFinished()) {
            $print->markFailed($e instanceof QrSigningKeyMissing
                ? QrSigningKeyMissing::USER_MESSAGE
                : 'สร้าง PDF ใบงานไม่ทันเวลา กรุณาลองใหม่');
        }
    }

    /**
     * Students of this batch still enrolled in the classroom, by เลขที่.
     *
     * @return list<WorksheetStudent>
     */
    private function students(Assignment $assignment): array
    {
        return $assignment->classroom->students()
            ->whereIn('users.id', $this->studentIds)
            ->get()
            ->map(fn (User $s) => new WorksheetStudent($s->id, $s->name, (int) $s->pivot->student_number))
            ->values()
            ->all();
    }
}
