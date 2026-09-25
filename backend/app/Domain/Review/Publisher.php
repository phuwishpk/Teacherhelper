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
 * A submission can be published when every question of its printed sheet
 * has an answer (SubmissionCoverage: page 2 may not be scanned yet) and
 * every answer is reviewed (reviewed_at). Publishing stores total_score = Σ final_score,
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
        $coverage = new SubmissionCoverage($submission->assignment()->firstOrFail());
        [$published] = $this->publish($submission->id, $teacher, $coverage, strict: true);

        return $published;
    }

    /**
     * skipped = not fully scanned or not fully reviewed yet (stays unpublished).
     *
     * @return array{published: int, already_published: int, skipped: int}
     */
    public function publishAssignment(Assignment $assignment, User $teacher): array
    {
        $counts = ['published' => 0, 'already_published' => 0, 'skipped' => 0];
        $coverage = new SubmissionCoverage($assignment);
        foreach ($assignment->submissions()->orderBy('id')->pluck('id') as $submissionId) {
            [$submission, $outcome] = $this->publish($submissionId, $teacher, $coverage, strict: false);
            if ($submission !== null) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    /**
     * @return array{0: Submission|null, 1: 'published'|'already_published'|'skipped'}
     */
    private function publish(int $submissionId, User $teacher, SubmissionCoverage $coverage, bool $strict): array
    {
        return DB::transaction(function () use ($submissionId, $teacher, $coverage, $strict) {
            $submission = Submission::query()->lockForUpdate()->find($submissionId);
            if ($submission === null) {
                return [null, 'skipped'];
            }
            if ($submission->isPublished()) {
                return [$submission, 'already_published'];
            }

            $responses = Response::query()->where('submission_id', $submission->id)->lockForUpdate()->get();
            $unreviewed = $responses->whereNull('reviewed_at')->count();
            $missing = $coverage->missing($submission, $responses->pluck('question_id'));
            if ($responses->isEmpty() || $unreviewed > 0 || $missing !== []) {
                if (! $strict) {
                    return [$submission, 'skipped'];
                }

                throw self::notReady($responses->isEmpty(), $unreviewed, $missing);
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

    /**
     * 409 submission_not_reviewed: errors.unreviewed = [count]; when pages
     * are missing also errors.missing_pages and errors.missing_questions
     * (question positions).
     *
     * @param  list<array{question_id: int, position: int, page: int|null}>  $missing
     */
    private static function notReady(bool $empty, int $unreviewed, array $missing): ApiException
    {
        $errors = ['unreviewed' => [(string) $unreviewed]];
        if ($empty) {
            return new ApiException('ยังไม่มีคำตอบของนักเรียนคนนี้ สแกนใบงานก่อนเผยแพร่', 'submission_not_reviewed', 409, $errors);
        }

        $parts = [];
        if ($missing !== []) {
            $positions = array_map(fn (array $m) => (string) $m['position'], $missing);
            $pages = array_map('strval', SubmissionCoverage::pages($missing));
            $parts[] = $pages === []
                ? 'ยังไม่มีคำตอบข้อ '.implode(', ', $positions)
                : 'ยังไม่ได้สแกนหน้า '.implode(', ', $pages).' (ข้อ '.implode(', ', $positions).')';
            $errors['missing_pages'] = $pages;
            $errors['missing_questions'] = $positions;
        }
        if ($unreviewed > 0) {
            $parts[] = "ยังตรวจทานไม่ครบ เหลืออีก {$unreviewed} ข้อ";
        }

        return new ApiException(implode(' และ', $parts), 'submission_not_reviewed', 409, $errors);
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
