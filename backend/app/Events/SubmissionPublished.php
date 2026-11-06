<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A submission became visible to its student (DESIGN §13, §9.5): published
 * alone or with the rest of the assignment. Carries ids only, so queued
 * listeners serialise nothing about the student.
 *
 * Listeners: NotifyStudentOfPublishedResult (FCM, §9.9). Google Classroom
 * grade push-back (§18.6 PushClassroomGradeJob) and mastery (§14.2) hook in
 * here as well.
 */
class SubmissionPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $submissionId,
        public readonly int $assignmentId,
        public readonly int $studentId,
        public readonly ?int $publishedBy,
    ) {}
}
