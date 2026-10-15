<?php

namespace App\Jobs;

use App\Domain\Worksheets\PdfMerger;
use App\Domain\Worksheets\WorksheetFiles;
use App\Models\WorksheetPrint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Last job of a worksheet print chain (DESIGN §5.5): joins the batch parts
 * into worksheets/{assignment}/{print}.pdf and marks the print `ready`.
 */
class MergeWorksheetsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(
        public readonly int $printId,
        public readonly int $batches,
    ) {
        $this->onQueue('pdf');
    }

    public function handle(PdfMerger $merger): void
    {
        $print = WorksheetPrint::query()->find($this->printId);
        if ($print === null || $print->isFinished()) {
            return;
        }

        $disk = Storage::disk('local');
        try {
            $started = hrtime(true);
            $parts = [];
            for ($batch = 0; $batch < $this->batches; $batch++) {
                $part = WorksheetFiles::part($print, $batch);
                if ($disk->exists($part)) {
                    $parts[] = $part;
                }
            }
            if ($parts === []) {
                // Worksheets and answer sheets have a part per batch of students; a booklet or key sheet always has one.
                $print->markFailed(in_array($print->kind, [WorksheetPrint::KIND_WORKSHEET, WorksheetPrint::KIND_ANSWER_SHEET], true)
                    ? 'ห้องนี้ไม่มีนักเรียนแล้ว'
                    : 'ไม่พบไฟล์'.$print->fileNoun().'ที่สร้างไว้ กรุณาสั่งพิมพ์ใหม่');

                return;
            }

            $target = WorksheetFiles::final($print);
            $disk->delete($target);
            if (count($parts) === 1) {
                $disk->move($parts[0], $target);
                $pages = PdfMerger::pageCount($disk->path($target));
            } else {
                $pages = $merger->merge(array_map(fn (string $p) => $disk->path($p), $parts), $disk->path($target));
            }
            WorksheetFiles::discardParts($print);

            $print->update(['status' => WorksheetPrint::STATUS_READY, 'file_path' => $target, 'error' => null]);

            Log::info('worksheets.merge', [
                'print_id' => $print->id,
                'parts' => count($parts),
                'pages' => $pages,
                'ms' => (int) ((hrtime(true) - $started) / 1e6),
            ]);
        } catch (Throwable $e) {
            $print->markFailed('รวมไฟล์'.$print->fileNoun().'ไม่สำเร็จ กรุณาลองใหม่');
            report($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        $print = WorksheetPrint::query()->find($this->printId);
        if ($print !== null && ! $print->isFinished()) {
            $print->markFailed('รวมไฟล์'.$print->fileNoun().'ไม่ทันเวลา กรุณาลองใหม่');
        }
    }
}
