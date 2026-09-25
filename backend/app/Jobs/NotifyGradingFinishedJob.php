<?php

namespace App\Jobs;

use App\Domain\Notifications\GradingNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The "grading done" notice held back by GradingNotices' cooldown, delayed
 * until the cooldown ends (a database-queue available_at, so the cron worker
 * picks it up; no scheduler needed). Carries ids only.
 */
class NotifyGradingFinishedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $assignmentId, public readonly int $dueAt) {}

    public function handle(GradingNotices $notices): void
    {
        $notices->followUp($this->assignmentId, $this->dueAt);
    }
}
