<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A confirmed rescan reopened a published submission (DESIGN §8.4, §13:
 * SubmissionStatus::refresh with reopen): published_at is cleared and the
 * page is graded and reviewed again, so the student sees nothing of it
 * until the next publish. Carries ids only.
 *
 * Listeners: RecordMasteryObservations removes the submission's
 * skill_observations (§14.2 counts only published results) until
 * SubmissionPublished writes them again.
 */
class SubmissionReopened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $submissionId,
        public readonly int $assignmentId,
        public readonly int $studentId,
    ) {}
}
