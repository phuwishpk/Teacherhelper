<?php

namespace App\Jobs;

use App\Domain\Google\ClassroomGradePusher;
use App\Domain\Google\GoogleApiException;
use App\Domain\Google\GoogleErrors;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one published total to Google Classroom (DESIGN §18.6 "ตอนเผยแพร่"),
 * dispatched by QueueClassroomGradePush on SubmissionPublished (and on
 * AppealResolved when the score changed) and by
 * POST /assignments/{id}/google-grades/retry. Carries the submission id only;
 * the total is read when the job runs, so a later change is never lost.
 *
 * Google unreachable / 429 / 5xx: tried again after 1 and 5 minutes; after
 * the last attempt the import row becomes `grade_failed` with last_error.
 * Other errors (reconnect needed, ProjectPermissionDenied, ...) fail at once.
 */
class PushClassroomGradeJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Fits the 50-second cron worker pass (§7.2). */
    public int $timeout = 40;

    public function __construct(public readonly int $submissionId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->submissionId;
    }

    public function handle(ClassroomGradePusher $pusher): void
    {
        try {
            $pusher->push($this->submissionId);
        } catch (GoogleApiException $e) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }
            $pusher->markFailed($this->submissionId, GoogleErrors::shortText($e));
        }
    }

    public function failed(?Throwable $e): void
    {
        app(ClassroomGradePusher::class)->markFailed($this->submissionId, 'ส่งคะแนนกลับ Google Classroom ไม่สำเร็จ ลองกดส่งคะแนนอีกครั้ง');
    }
}
