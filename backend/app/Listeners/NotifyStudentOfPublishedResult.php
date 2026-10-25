<?php

namespace App\Listeners;

use App\Domain\Notifications\Notifier;
use App\Events\SubmissionPublished;
use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * "ผลการบ้าน {title} ออกแล้ว" to the student (DESIGN §9.9), from the cron
 * worker (queue `default`), only if the submission is still published when
 * the job runs.
 */
class NotifyStudentOfPublishedResult implements ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 1;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(SubmissionPublished $event): void
    {
        $submission = Submission::query()->with('assignment')->find($event->submissionId);
        if ($submission === null || ! $submission->isPublished()) {
            return;
        }

        try {
            $this->notifier->resultPublished($submission);
        } catch (Throwable $e) {
            report($e); // a push outage must not fail or repeat the job
        }
    }
}
