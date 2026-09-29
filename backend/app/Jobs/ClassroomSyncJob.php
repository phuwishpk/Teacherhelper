<?php

namespace App\Jobs;

use App\Domain\Google\ClassroomSync;
use App\Domain\Google\GoogleOAuth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * One round of the Google Classroom sync (DESIGN §19.3, §19.10), queued by
 * eduvision:queue-work when at least 5 minutes passed since the last round
 * (dispatchIfDue: Cache::add on the database cache is both the timestamp and
 * the guard against overlapping rounds), or for one classroom by
 * POST /classrooms/{id}/google-sync. Not retried: the next round is the retry.
 */
class ClassroomSyncJob implements ShouldQueue
{
    use Queueable;

    public const LOCK = 'classroom-sync:lock';

    public const INTERVAL_SECONDS = 300;

    public int $tries = 1;

    /** The round stops starting work after 40 s; one Google call may still take GOOGLE_TIMEOUT. */
    public int $timeout = 90;

    public function __construct(public readonly ?int $classroomId = null)
    {
        $this->onQueue('default');
    }

    /** The cron's hook: queues a round unless one ran in the last 5 minutes. */
    public static function dispatchIfDue(): bool
    {
        if (! GoogleOAuth::isConfigured()) {
            return false;
        }
        if (! Cache::add(self::LOCK, now()->toIso8601String(), self::INTERVAL_SECONDS)) {
            return false;
        }
        self::dispatch();

        return true;
    }

    public function handle(ClassroomSync $sync): void
    {
        $sync->run($this->classroomId);
    }
}
