<?php

namespace App\Domain\Worksheets;

use App\Exceptions\ApiException;
use App\Jobs\MergeWorksheetsJob;
use App\Jobs\RenderWorksheetsJob;
use App\Models\Assignment;
use App\Models\User;
use App\Models\WorksheetPrint;
use Illuminate\Support\Facades\Bus;

/**
 * POST /assignments/{id}/worksheets (DESIGN §5.5, §9.3): one PDF with every
 * student's copy of the current layout version. The classroom is split into
 * batches (eduvision.worksheets.batch_size, default 10) so each
 * RenderWorksheetsJob fits in one 50-second worker pass; MergeWorksheetsJob
 * joins the parts. The jobs run as a chain on the `pdf` queue.
 */
class WorksheetPrintService
{
    public function queue(Assignment $assignment, User $teacher): WorksheetPrint
    {
        if (! $assignment->isReady() || $assignment->current_layout_version === null) {
            throw new ApiException(
                'ต้องสร้าง layout ให้เป็นปัจจุบันก่อนพิมพ์ใบงาน',
                'assignment_not_ready',
                409,
            );
        }

        $studentIds = $assignment->classroom->students()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        if ($studentIds === []) {
            throw new ApiException('ห้องนี้ยังไม่มีนักเรียน', 'classroom_empty', 422);
        }

        $print = WorksheetPrint::create([
            'assignment_id' => $assignment->id,
            'layout_version' => $assignment->current_layout_version,
            'requested_by' => $teacher->id,
            'status' => WorksheetPrint::STATUS_QUEUED,
        ]);

        $batches = array_chunk($studentIds, (int) config('eduvision.worksheets.batch_size', 10));
        $jobs = [];
        foreach ($batches as $index => $ids) {
            $jobs[] = new RenderWorksheetsJob($print->id, $index, $ids);
        }
        $jobs[] = new MergeWorksheetsJob($print->id, count($batches));

        Bus::chain($jobs)->onQueue('pdf')->dispatch();

        return $print;
    }
}
