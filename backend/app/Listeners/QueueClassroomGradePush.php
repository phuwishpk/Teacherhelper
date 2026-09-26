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
        if (AssignmentGoogleLink::query()->whereKey($event->assignmentId)->exists()) {
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
        if ($submission !== null && $submission->isPublished()
            && AssignmentGoogleLink::query()->whereKey($submission->assignment_id)->exists()) {
            PushClassroomGradeJob::dispatch($submission->id);
        }
    }
}
