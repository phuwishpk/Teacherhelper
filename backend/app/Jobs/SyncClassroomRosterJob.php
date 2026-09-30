<?php

namespace App\Jobs;

use App\Domain\Google\GoogleRosterSync;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The roster sync of one linked classroom in the background (DESIGN §19.2,
 * §19.10), with the Google account of the teacher who linked the course.
 * Dispatched when a submission sync meets a Google user that is not matched
 * yet, at most once per classroom per sync round (5 minutes).
 *
 * A Google error (reconnect needed, course gone, Google down) is logged and
 * the job ends: the next round tries again, and the teacher still has the
 * "ซิงก์รายชื่อ" button, which shows the error.
 */
class SyncClassroomRosterJob implements ShouldQueue
{
    use Queueable;

    /** One sync round of the cron (§19.3). */
    public const ONCE_PER_SECONDS = 300;

    public int $tries = 1;

    /** Fits the 50-second cron worker pass (§7.2). */
    public int $timeout = 40;

    public function __construct(public readonly int $classroomId)
    {
        $this->onQueue('default');
    }

    /** Dispatches unless this classroom had one in the last 5 minutes. */
    public static function dispatchOncePerRound(int $classroomId): bool
    {
        if (! Cache::add("classroom-roster-sync:{$classroomId}", now()->toIso8601String(), self::ONCE_PER_SECONDS)) {
            return false;
        }
        self::dispatch($classroomId);

        return true;
    }

    public function handle(GoogleRosterSync $sync): void
    {
        $classroom = Classroom::query()->with('googleLink')->find($this->classroomId);
        $link = $classroom?->googleLink;
        if ($classroom === null || $link === null) {
            return;
        }
        $teacher = User::query()->find($link->owner_user_id);
        if ($teacher === null) {
            return;
        }

        try {
            // Nobody sees the PINs of new students here: they wait as pin_pending_at.
            $sync->sync($teacher, $classroom, background: true);
        } catch (ApiException|ValidationException $e) {
            Log::warning('google.roster_sync_failed', [
                'classroom_id' => $classroom->id,
                'code' => $e instanceof ApiException ? $e->errorCode : 'validation_failed',
            ]);
        }
    }
}
