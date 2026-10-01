<?php

namespace App\Jobs;

use App\Domain\Google\GoogleRosterSync;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The roster sync of a linked classroom in the background (DESIGN §19.2,
 * §19.10, §24.10): every course linked to it (one per teacher), each with
 * the Google account of the teacher who linked it, or only $linkId (a course
 * just linked by approving an import request). Dispatched when a submission
 * sync meets a Google user that is not matched yet, at most once per
 * classroom per sync round (5 minutes).
 *
 * The rosters are read once: a course whose roster cannot be read is
 * skipped, and nobody is marked left in that round (GoogleRosterSync).
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

    /** Not promoted, so a job queued before build 4 unserializes with null (every course). */
    public ?int $linkId = null;

    public function __construct(public readonly int $classroomId, ?int $linkId = null)
    {
        $this->linkId = $linkId;
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
        $classroom = Classroom::query()->find($this->classroomId);
        if ($classroom === null || $classroom->closed_at !== null) {
            return;
        }
        $links = ClassroomGoogleLink::query()->where('classroom_id', $classroom->id)->orderBy('id')->get();
        $rosters = [];
        foreach ($links as $link) {
            $rosters[$link->id] = $sync->rosterOf($link);
        }

        foreach ($links as $link) {
            if (($this->linkId !== null && $link->id !== $this->linkId) || $rosters[$link->id] === null) {
                continue;
            }
            try {
                // Nobody sees the PINs of new students here: they wait as pin_pending_at.
                $sync->apply($classroom, $link, $rosters[$link->id], true, $sync->elsewhere($classroom, $link, $rosters));
            } catch (ApiException|ValidationException $e) {
                Log::warning('google.roster_sync_failed', [
                    'classroom_id' => $classroom->id,
                    'link_id' => $link->id,
                    'code' => $e instanceof ApiException ? $e->errorCode : 'validation_failed',
                ]);
            }
        }
    }
}
