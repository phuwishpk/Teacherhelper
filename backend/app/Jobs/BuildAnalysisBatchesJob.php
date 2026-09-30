<?php

namespace App\Jobs;

use App\Domain\Analysis\AnalysisBatches;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The nightly analysis round (DESIGN §20.8 step 1–2): queued by
 * eduvision:queue-work on its first run at or after 01:00 Asia/Bangkok
 * each day (Cache::add of the Thai date, 36 hours, is the once-a-day
 * guard), then AnalysisBatches::build() submits the students whose
 * mastery changed. Not retried: the next night is the retry. $timeout
 * stays below the database queue's retry_after (300 s).
 */
class BuildAnalysisBatchesJob implements ShouldQueue
{
    use Queueable;

    public const LOCK_PREFIX = 'analysis-nightly:';

    public const TIMEZONE = 'Asia/Bangkok';

    public const HOUR = 1;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /** The cron's hook: queues the round once per Thai day, from 01:00. */
    public static function dispatchIfDue(?CarbonInterface $now = null): bool
    {
        $local = Carbon::instance($now ?? now())->setTimezone(self::TIMEZONE);
        if ($local->hour < self::HOUR) {
            return false;
        }
        if (! Cache::add(self::LOCK_PREFIX.$local->toDateString(), true, now()->addHours(36))) {
            return false;
        }
        self::dispatch();

        return true;
    }

    public function handle(AnalysisBatches $batches): void
    {
        $stats = $batches->build();
        Log::info('analysis.nightly', $stats);
    }
}
