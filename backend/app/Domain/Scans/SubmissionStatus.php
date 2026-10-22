<?php

namespace App\Domain\Scans;

use App\Models\Response;
use App\Models\Submission;

/**
 * Derives submissions.status from its responses (DESIGN §8.4, §13):
 *
 *   no responses                                   awaiting_scan
 *   any response queued / extracted / failed       grading
 *   all scored or manual, some not yet reviewed    needs_review
 *   all reviewed by the teacher (reviewed_at)      reviewed
 *
 * `published` is set only by publishing and is kept here, except when
 * $reopen is true: a confirmed rescan of a published page sends the
 * submission back through grading and review (published_at / published_by
 * are cleared; students see it again after the next publish).
 *
 * Call inside the transaction that holds the submission row lock.
 */
final class SubmissionStatus
{
    public static function refresh(Submission $submission, bool $reopen = false): string
    {
        if ($submission->isPublished() && ! $reopen) {
            return $submission->status;
        }

        $total = $submission->responses()->count();
        $inProgress = $submission->responses()->whereIn('grading_state', Response::IN_PROGRESS_STATES)->count();
        $unreviewed = $submission->responses()->whereNull('reviewed_at')->count();

        $status = match (true) {
            $total === 0 => Submission::STATUS_AWAITING_SCAN,
            $inProgress > 0 => Submission::STATUS_GRADING,
            $unreviewed > 0 => Submission::STATUS_NEEDS_REVIEW,
            default => Submission::STATUS_REVIEWED,
        };

        $submission->status = $status;
        if ($status !== Submission::STATUS_PUBLISHED) {
            $submission->published_at = null;
            $submission->published_by = null;
        }
        $submission->save();

        return $status;
    }
}
