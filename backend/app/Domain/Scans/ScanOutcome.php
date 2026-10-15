<?php

namespace App\Domain\Scans;

use App\Models\Scan;

/**
 * The answer to POST /scans (DESIGN §9.4):
 *   201 {scan_id, submission_id, state: "active"}          stored, grading queued
 *   202 {scan_id, submission_id, state: "pending_confirm"} rescan of a published submission
 *   200 same shape                                          client_scan_id seen before (retry)
 * The replay reports the scan's current state, so a retry after the teacher
 * confirmed (or a later scan superseded it) reflects what the server holds now.
 */
final readonly class ScanOutcome
{
    public function __construct(public Scan $scan, public int $status) {}

    public static function created(Scan $scan): self
    {
        return new self($scan, $scan->isPendingConfirm() ? 202 : 201);
    }

    public static function replayed(Scan $scan): self
    {
        return new self($scan, 200);
    }

    /**
     * @return array{scan_id: int, submission_id: int, state: string}
     */
    public function body(): array
    {
        return [
            'scan_id' => $this->scan->id,
            'submission_id' => $this->scan->submission_id,
            'state' => $this->scan->state,
        ];
    }
}
