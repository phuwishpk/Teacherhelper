<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proves that cron -> artisan -> database queue works end to end on the host
 * (KICKOFF B5): dispatched by `eduvision:queue-work` right before the worker
 * runs, so the worker itself must pick it up and write the timestamp that
 * GET /api/v1/health reports as queue_last_run_at.
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'queue.last_run_at';

    public function handle(): void
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());
    }
}
