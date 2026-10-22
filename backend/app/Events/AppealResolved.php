<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The teacher accepted or rejected an appeal (DESIGN §13). scoreChanged is
 * true when final_score or final_understanding changed; submissions.total_score
 * has then been recomputed (Publisher::refreshTotal). It is the cue to
 * recompute mastery (§14.2) and, like SubmissionPublished, to push the new
 * total to Google Classroom: PushClassroomGradeJob (§18.6) must run on this
 * event too when scoreChanged is true, or Classroom keeps the old
 * assignedGrade. Listeners: NotifyStudentOfAppealResolution (FCM, §9.9).
 */
class AppealResolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $appealId,
        public readonly int $responseId,
        public readonly int $studentId,
        public readonly bool $scoreChanged,
    ) {}
}
