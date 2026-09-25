<?php

namespace App\Domain\Review;

use App\Events\SubmissionPublished;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Response;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Publishing (DESIGN §9.5, §13): students see nothing until the teacher
 * publishes, one submission at a time or every fully reviewed submission of
 * the assignment at once.
 *
 * A submission can be published when it has answers and every one is
 * reviewed (reviewed_at). Publishing stores total_score = Σ final_score,
 * published_at / published_by, and fires SubmissionPublished after the
 * commit (student push, Classroom grade, mastery). Publishing an already
 * published submission changes nothing and fires nothing.
 */
final class Publisher
{
    /**
     * @throws ApiException 409 submission_not_reviewed
     */
    public function publishSubmission(Submission $submission, User $teacher): Submission
    {
        [$published] = $this->publish($submission->id, $teacher, strict: true);

        return $published;
    }

    /**
     * @return array{published: int, already_published: int, skipped: int}
     *                                                                     skipped = not fully reviewed yet (stay unpublished)
     */
    public function publishAssignment(Assignment $assignment, User $teacher): array
    {
        $counts = ['published' => 0, 'already_published' => 0, 'skipped' => 0];
        foreach ($assignment->submissions()->orderBy('id')->pluck('id') as $submissionId) {
            [$submission, $outcome] = $this->publish($submissionId, $teacher, strict: false);
            if ($submission !== null) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    /**
     * @return array{0: Submission|null, 1: 'published'|'already_published'|'skipped'}
     */
    private function publish(int $submissionId, User $teacher, bool $strict): array
    {
        return DB::transaction(function () use ($submissionId, $teacher, $strict) {
            $submission = Submission::query()->lockForUpdate()->find($submissionId);
            if ($submission === null) {
                return [null, 'skipped'];
            }
            if ($submission->isPublished()) {
                return [$submission, 'already_published'];
            }

            $responses = Response::query()->where('submission_id', $submission->id)->lockForUpdate()->get();
            $unreviewed = $responses->whereNull('reviewed_at')->count();
            if ($responses->isEmpty() || $unreviewed > 0) {
                if (! $strict) {
                    return [$submission, 'skipped'];
                }
                $message = $responses->isEmpty()
                    ? 'ยังไม่มีคำตอบของนักเรียนคนนี้ สแกนใบงานก่อนเผยแพร่'
                    : "ยังตรวจทานไม่ครบ เหลืออีก {$unreviewed} ข้อ";

                throw new ApiException($message, 'submission_not_reviewed', 409, [
                    'unreviewed' => [(string) $unreviewed],
                ]);
            }

            $submission->total_score = round((float) $responses->sum(fn (Response $r) => (float) $r->effectiveScore()), 2);
            $submission->status = Submission::STATUS_PUBLISHED;
            $submission->published_at = now();
            $submission->published_by = $teacher->id;
            $submission->save();

            SubmissionPublished::dispatch($submission->id, $submission->assignment_id, $submission->student_id, $teacher->id);

            return [$submission, 'published'];
        });
    }

    /** Σ of the effective scores; kept in step after an appeal changes one. */
    public static function refreshTotal(Submission $submission): void
    {
        $total = Response::query()->where('submission_id', $submission->id)->get()
            ->sum(fn (Response $r) => (float) $r->effectiveScore());
        $submission->total_score = round((float) $total, 2);
        $submission->save();
    }
}
