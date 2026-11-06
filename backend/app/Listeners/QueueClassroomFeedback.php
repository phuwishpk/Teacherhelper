<?php

namespace App\Listeners;

use App\Domain\Google\ClassroomFeedback;
use App\Events\SubmissionPublished;
use App\Jobs\PostClassroomFeedbackJob;

/**
 * Queues the private announcement of a published result (DESIGN §19.7) for
 * every assignment in Google Classroom, courseWork the app posted or
 * mirrored from the Classroom website alike: one classroom_feedback_posts
 * row per publish, then PostClassroomFeedbackJob from the cron worker.
 * Runs inline after the commit and makes no Google call.
 */
class QueueClassroomFeedback
{
    public function handle(SubmissionPublished $event): void
    {
        $post = ClassroomFeedback::queue($event->submissionId, $created);
        if ($post !== null && $created) {
            PostClassroomFeedbackJob::dispatch($post->id);
        }
    }
}
