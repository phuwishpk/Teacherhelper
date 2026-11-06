<?php

namespace App\Jobs;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Google\AssignmentGooglePosts;
use App\Domain\Google\CourseWorkPoster;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Posts a ready assignment to Google Classroom as the teacher who manages
 * it (DESIGN §30.12), the way "โพสต์ลง Classroom" does by hand. Google
 * unreachable: tried again after 1 and 5 minutes; any other refusal is
 * kept on the assignment for the teacher to read and retry.
 */
class PostCourseWorkJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Up to five 10 MB uploads to Drive in one go (DESIGN §30.2). */
    public int $timeout = 110;

    public function __construct(public readonly int $assignmentId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->assignmentId;
    }

    public function handle(CourseWorkPoster $poster): void
    {
        $assignment = Assignment::query()->with(['classroom', 'course'])->find($this->assignmentId);
        if ($assignment === null) {
            return;
        }
        if ($assignment->googleLink()->exists() || ! $assignment->isReady() || ! $assignment->send_to_google) {
            AssignmentGooglePosts::clear($assignment);

            return;
        }
        $managerId = ClassroomAccess::managerId($assignment);
        $manager = $managerId === null ? null : User::query()->find($managerId);
        if ($manager === null) {
            AssignmentGooglePosts::fail($assignment, 'ไม่พบครูผู้ดูแลการบ้านนี้');

            return;
        }
        try {
            $poster->post($manager, $assignment, ['attach_blank_worksheet' => false]);
        } catch (ApiException $e) {
            if (in_array($e->errorCode, ['google_unavailable', 'google_post_in_progress'], true) && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }
            if ($e->errorCode === 'already_posted') {
                AssignmentGooglePosts::clear($assignment->refresh());

                return;
            }
            AssignmentGooglePosts::fail($assignment->refresh(), $e->getMessage());
        }
    }

    public function failed(?Throwable $e): void
    {
        $assignment = Assignment::query()->find($this->assignmentId);
        if ($assignment !== null && ! $assignment->googleLink()->exists()) {
            AssignmentGooglePosts::fail($assignment, 'โพสต์ลง Google Classroom ไม่สำเร็จ กดโพสต์อีกครั้ง');
        }
    }
}
