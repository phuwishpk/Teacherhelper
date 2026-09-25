<?php

namespace App\Jobs;

use App\Models\Response;
use App\Models\Scan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Grades the non-mcq responses of one scan (DESIGN §7.2): Gemini extraction
 * (up to 8 in parallel with Http::pool), fuzzy systems 1 and 2, explanations
 * for answers that did not get full marks, attempts/backoff, `manual` after
 * 3 failures, and the "grading done" push to the teacher.
 *
 * ---------------------------------------------------------------------
 * STUB (step B3). POST /scans dispatches this job on the `grading` queue
 * so the ingestion contract is final; step B4 implements handle(). Until
 * then the job only logs what it would grade and leaves the responses
 * `queued`. When implementing: a later rescan can requeue a response
 * while this job is running, so save a result only if the response still
 * belongs to $scanId (compare scan_id before writing).
 * ---------------------------------------------------------------------
 */
class GradeScanJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'grading';

    public function __construct(public readonly int $scanId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $scan = Scan::query()->find($this->scanId);
        if ($scan === null) {
            return;
        }

        // Grade by response, not by scan state: a rescan moves the responses it
        // covers to the new scan, but a response whose question sits on another
        // page in the newer layout version keeps pointing at this (superseded)
        // scan and still needs grading.
        // STUB: B4 replaces this with the grading pipeline described above.
        Log::info('grade_scan.stub', [
            'scan_id' => $scan->id,
            'state' => $scan->state,
            'queued' => Response::query()
                ->where('scan_id', $scan->id)
                ->whereIn('grading_state', [Response::STATE_QUEUED, Response::STATE_FAILED])
                ->count(),
        ]);
    }
}
