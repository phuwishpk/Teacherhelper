<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeatJob;
use Illuminate\Console\Command;

/**
 * Wrapper the Plesk Scheduled Task runs every minute (no daemons on shared
 * hosting). Dispatches the heartbeat, then runs one bounded worker pass with
 * exactly the options from DESIGN §7.2. No --tries: GradeScanJob (Phase 3)
 * counts its own attempts.
 */
class QueueWorkCommand extends Command
{
    protected $signature = 'eduvision:queue-work';

    protected $description = 'Dispatch the queue heartbeat, then run one bounded queue:work pass (cron entry point)';

    public function handle(): int
    {
        QueueHeartbeatJob::dispatch();

        return $this->call('queue:work', [
            '--queue' => 'grading,default,pdf',
            '--stop-when-empty' => true,
            '--max-time' => 50,
        ]);
    }
}
