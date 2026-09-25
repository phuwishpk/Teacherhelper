<?php

namespace App\Domain\Worksheets;

use App\Exceptions\ApiException;
use App\Jobs\MergeWorksheetsJob;
use App\Jobs\RenderWorksheetsJob;
use App\Models\Assignment;
use App\Models\User;
use App\Models\WorksheetPrint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

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
        // Without the key every render job would fail while resolving the
        // renderer; refuse up front with a message that names the real cause
        // (ApiException is not logged, so record it for the operator here).
        if (! QrSigner::isConfigured()) {
            Log::error('worksheets.qr_key_missing', ['assignment_id' => $assignment->id]);

            throw new ApiException(QrSigningKeyMissing::USER_MESSAGE, 'qr_key_missing', 503);
        }

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
