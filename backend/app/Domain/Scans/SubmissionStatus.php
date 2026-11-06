<?php

namespace App\Domain\Scans;

use App\Models\Assignment;
use App\Models\ExamSheetRead;
use App\Models\Response;
use App\Models\Scan;
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

        return $status === Submission::STATUS_PUBLISHED ? $status : self::holdIncompleteExam($submission);
    }

    /**
     * An exam submission with a page still waiting for its version, or with a
     * page of its sheet missing (bubble or written, DESIGN §22.11, §22.20.5),
     * stays in needs_review even when every answer so far is reviewed, so it
     * is never published incomplete.
     */
    private static function holdIncompleteExam(Submission $submission): string
    {
        $status = $submission->status;
        if (! in_array($status, [Submission::STATUS_AWAITING_SCAN, Submission::STATUS_REVIEWED], true)) {
            return $status;
        }
        $exam = Assignment::query()->find($submission->assignment_id);
        if (! $exam instanceof Assignment || ! $exam->isExam()) {
            return $status;
        }
        $active = Scan::query()->where('submission_id', $submission->id)->where('state', Scan::STATE_ACTIVE);
        $pages = (clone $active)->distinct()->pluck('page_no')->count();
        $waiting = ExamSheetRead::query()->whereIn('scan_id', (clone $active)->select('id'))->whereNull('version_no')->exists();
        $pageCount = $exam->currentLayout()?->pageCount() ?? 0;
        if ($pages > 0 && ($waiting || $pages < $pageCount)) {
            $submission->status = Submission::STATUS_NEEDS_REVIEW;
            $submission->save();
        }

        return $submission->status;
    }
}
