<?php

namespace App\Listeners;

use App\Events\AppealResolved;
use App\Events\SubmissionPublished;
use App\Jobs\PushClassroomGradeJob;
use App\Models\AssignmentGoogleLink;
use App\Models\Response;
use App\Models\Submission;

/**
 * Queues the Classroom grade push (DESIGN §18.6): when a submission of an
 * assignment posted to Google Classroom is published, and when an appeal
 * changed the score of a published one (AppealResolved docs). Runs inline
 * after the commit and only inserts a job; PushClassroomGradeJob does the
 * Google calls from the cron worker.
 */
class QueueClassroomGradePush
{
    public function handleSubmissionPublished(SubmissionPublished $event): void
    {
        if (self::pushable($event->assignmentId)) {
            PushClassroomGradeJob::dispatch($event->submissionId);
        }
    }

    public function handleAppealResolved(AppealResolved $event): void
    {
        if (! $event->scoreChanged) {
            return;
        }
        $submissionId = Response::query()->whereKey($event->responseId)->value('submission_id');
        $submission = $submissionId !== null ? Submission::query()->find($submissionId) : null;
        if ($submission !== null && $submission->isPublished() && self::pushable($submission->assignment_id)) {
            PushClassroomGradeJob::dispatch($submission->id);
        }
    }

    /** Posted by the app: courseWork created on the Classroom website takes no grades (§19.3). */
    private static function pushable(int $assignmentId): bool
    {
        return AssignmentGoogleLink::query()
            ->whereKey($assignmentId)
            ->where('origin', AssignmentGoogleLink::ORIGIN_APP)
            ->exists();
    }
}
