<?php

namespace App\Console\Commands;

use App\Jobs\ClassroomSyncJob;
use App\Jobs\QueueHeartbeatJob;
use Illuminate\Console\Command;

/**
 * Wrapper the Plesk Scheduled Task runs every minute (no daemons on shared
 * hosting). Dispatches the heartbeat and, when due, a Google Classroom sync
 * round (ClassroomSyncJob), then runs one bounded worker pass with
 * exactly the options from DESIGN §7.2. No --tries: GradeScanJob (Phase 3)
 * counts its own attempts. --memory is queue:work's graceful-exit threshold
 * (MB, default 128 like queue:work itself); raise it only when the host's PHP
 * memory_limit is higher, or for a coverage run that holds a whole test
 * suite in the same process.
 */
class QueueWorkCommand extends Command
{
    protected $signature = 'eduvision:queue-work
                            {--memory=128 : Memory in MB after which the worker pass stops gracefully (queue:work --memory)}';

    protected $description = 'Dispatch the queue heartbeat, then run one bounded queue:work pass (cron entry point)';

    public function handle(): int
    {
        QueueHeartbeatJob::dispatch();
        // The Google Classroom sync round, at most every 5 minutes (DESIGN §19.3, §19.10).
        ClassroomSyncJob::dispatchIfDue();

        return $this->call('queue:work', [
            '--queue' => 'grading,default,pdf',
            '--stop-when-empty' => true,
            '--max-time' => 50,
            '--memory' => max(32, (int) $this->option('memory')),
        ]);
    }
}
