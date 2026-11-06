<?php

namespace App\Jobs;

use App\Domain\Google\ClassroomFeedback;
use App\Domain\Google\GoogleApiException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one private announcement of a published result to Google Classroom
 * (DESIGN §19.7, §19.10), dispatched by QueueClassroomFeedback on
 * SubmissionPublished and by POST /assignments/{id}/google-feedback/retry.
 * Carries the classroom_feedback_posts id only; the text is built when the
 * job runs.
 *
 * Google unreachable / 429 / 5xx: tried again after 1 and 5 minutes; after
 * the last attempt the row becomes `failed` with last_error. Other errors
 * (reconnect needed, missing announcements scope, ...) fail at once.
 */
class PostClassroomFeedbackJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Fits the 50-second cron worker pass (§7.2). */
    public int $timeout = 40;

    public function __construct(public readonly int $postId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->postId;
    }

    public function handle(ClassroomFeedback $feedback): void
    {
        try {
            $feedback->post($this->postId);
        } catch (GoogleApiException $e) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }
            $feedback->markFailed($this->postId, ClassroomFeedback::errorText($e));
        }
    }

    public function failed(?Throwable $e): void
    {
        app(ClassroomFeedback::class)->markFailed($this->postId, 'ส่งประกาศผลตรวจไป Google Classroom ไม่สำเร็จ กดส่งประกาศอีกครั้ง');
    }
}
