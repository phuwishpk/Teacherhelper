<?php

namespace App\Domain\Google;

use App\Domain\Classrooms\ClassroomAccess;
use App\Jobs\PostCourseWorkJob;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomGoogleLink;
use Illuminate\Support\Facades\Log;

/**
 * The automatic Classroom post of homework (DESIGN §30.12): when an
 * assignment becomes ready with "ส่งไป Google Classroom ด้วย" on and the
 * teacher who manages it linked the classroom, the courseWork is posted
 * from the queue. Its state lives on the assignment until the post exists
 * (then assignment_google_links is the state).
 */
final class AssignmentGooglePosts
{
    public const STATE_QUEUED = 'queued';

    public const STATE_FAILED = 'failed';

    /** Called where an assignment's status flips to ready. */
    public static function onReady(Assignment $assignment): void
    {
        if (! $assignment->isReady() || $assignment->isExam() || ! $assignment->send_to_google) {
            return;
        }
        if ($assignment->googleLink()->exists() || ClassroomGoogleLink::forAssignment($assignment) === null) {
            return;
        }
        if (ClassroomAccess::managerId($assignment) === null) {
            return;
        }
        $assignment->forceFill(['google_post_state' => self::STATE_QUEUED, 'google_post_error' => null])->save();
        PostCourseWorkJob::dispatch($assignment->id);
    }

    public static function clear(Assignment $assignment): void
    {
        if ($assignment->google_post_state !== null || $assignment->google_post_error !== null) {
            $assignment->forceFill(['google_post_state' => null, 'google_post_error' => null])->save();
        }
    }

    public static function fail(Assignment $assignment, string $reason): void
    {
        $assignment->forceFill(['google_post_state' => self::STATE_FAILED, 'google_post_error' => mb_substr($reason, 0, 255)])->save();
        Log::warning('google.course_work_not_posted', ['assignment_id' => $assignment->id, 'reason' => $reason]);
    }

    /**
     * The `google_post` of the assignment payload: sent once the courseWork
     * exists (either way it got there), else the automatic post's state.
     *
     * @return array{state: string, last_error: string|null}
     */
    public static function payload(Assignment $assignment, ?AssignmentGoogleLink $link): array
    {
        if ($link !== null) {
            return ['state' => 'sent', 'last_error' => null];
        }

        return ['state' => $assignment->google_post_state ?? 'not_sent', 'last_error' => $assignment->google_post_error];
    }
}
