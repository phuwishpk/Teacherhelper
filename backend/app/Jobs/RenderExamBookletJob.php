<?php

namespace App\Jobs;

use App\Domain\Exams\ExamBooklet;
use App\Domain\Worksheets\WorksheetFiles;
use App\Domain\Worksheets\WorksheetLayoutException;
use App\Models\Assignment;
use App\Models\ExamVersion;
use App\Models\WorksheetPrint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders the question booklet of one exam version (DESIGN §22.6 item 1,
 * §22.16) on the `pdf` queue straight into worksheets/{assignment}/{print}.pdf.
 * One file per version; the teacher prints as many copies as needed.
 */
class RenderExamBookletJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public readonly int $printId)
    {
        $this->onQueue('pdf');
    }

    public function handle(ExamBooklet $booklet): void
    {
        $print = WorksheetPrint::query()->find($this->printId);
        if ($print === null || $print->isFinished()) {
            return;
        }
        $print->update(['status' => WorksheetPrint::STATUS_RENDERING]);

        try {
            $started = hrtime(true);
            $exam = Assignment::query()->with(['course', 'subject'])->findOrFail($print->assignment_id);
            $version = ExamVersion::query()
                ->where('assignment_id', $exam->id)
                ->where('version_no', $print->version_no)
                ->first();
            if ($version === null) {
                $print->markFailed('ไม่พบชุดข้อสอบนี้แล้ว กรุณาสั่งพิมพ์ใหม่');

                return;
            }

            $plan = $booklet->plan($exam, $version);
            $target = WorksheetFiles::final($print);
            Storage::disk('local')->put($target, $booklet->render($exam, $version, $plan));
            $print->update(['status' => WorksheetPrint::STATUS_READY, 'file_path' => $target, 'error' => null]);

            Log::info('exams.render_booklet', [
                'print_id' => $print->id,
                'version_no' => $version->version_no,
                'pages' => $plan->pageCount(),
                'bytes' => Storage::disk('local')->size($target),
                'ms' => (int) ((hrtime(true) - $started) / 1e6),
            ]);
        } catch (WorksheetLayoutException $e) {
            $print->markFailed($e->getMessage());
        } catch (Throwable $e) {
            $print->markFailed('สร้าง PDF เล่มข้อสอบไม่สำเร็จ กรุณาลองใหม่');
            report($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        $print = WorksheetPrint::query()->find($this->printId);
        if ($print !== null && ! $print->isFinished()) {
            $print->markFailed('สร้าง PDF เล่มข้อสอบไม่ทันเวลา กรุณาลองใหม่');
        }
    }
}
