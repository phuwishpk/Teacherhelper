<?php

namespace App\Domain\Exams;

use App\Domain\Grading\ClassRegrade;
use App\Domain\Review\ScoreRules;
use App\Events\SubmissionReopened;
use App\Exceptions\ApiException;
use App\Jobs\RescoreExamJob;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "ตรวจใหม่ทั้งห้อง" of an exam (DESIGN §22.3, §22.16): POST
 * /assignments/{id}/regrade and /regrade/estimate send exams here instead
 * of ClassRegrade. Every answer is scored again BY CODE against the current
 * key and points (ExamAnswerScore): no Gemini, no key needed, the estimate
 * is always 0 baht.
 *
 * - the answer used is exam_answer.resolved when the teacher read the marks
 *   (so a resolved double mark never falls back to 0), otherwise the
 *   bubbles read from the sheet;
 * - skipped: answers the teacher overrode (ClassRegrade::overridden(): a
 *   PATCH /responses score different from the code's, an accepted appeal)
 *   unless include_overridden; resolved answers are never overridden
 *   (ai_score = final_score);
 * - an answer whose score does not change is left alone and not counted;
 * - a rescored answer: resolved -> ai = final, still reviewed; a doubtful
 *   answer the teacher already reviewed at the code's score (PATCH, not an
 *   override) -> ai = final, still reviewed; a doubtful answer the teacher
 *   has not read -> ai only, back in the review queue; a clear one -> ai = final, reviewed at once (reviewed_by NULL),
 *   like a freshly scanned page. Logged as `rescan` (actor teacher, reason
 *   ClassRegrade::REASON) and `ai_scored` (actor system);
 * - a published submission with a changed answer is reopened like a
 *   confirmed rescan (§21.13, SubmissionReopened).
 *
 * run() plans in the request (the counts it answers with) and queues
 * RescoreExamJob on `grading` to write; a class of 50 x 200 answers is too
 * many row writes for one request on the shared host. While the job's
 * marker is younger than ClassRegrade::LOST_MINUTES another run answers 409
 * regrade_in_progress.
 */
final class ExamRegrade
{
    /**
     * @return array<string, mixed> the shape of ClassRegrade::estimate()
     *
     * @throws ApiException 409 answer_key_not_approved, 422 exam_manual_grading
     */
    public function estimate(Assignment $exam, bool $includeOverridden): array
    {
        self::assertRegradable($exam);
        $plan = self::plan($exam, $includeOverridden);

        return [
            'submissions' => $plan['submissions'],
            'queued_responses' => 0,
            'mcq_by_code' => $plan['changed'],
            'whole_page_pages' => 0,
            'skipped_overridden' => $plan['skipped_overridden'],
            'skipped_in_progress' => 0,
            'skipped_missing_image' => 0,
            'published_submissions' => $plan['published'],
            'in_progress' => self::inProgress($exam),
            'estimate' => ['input_tokens' => 0, 'output_tokens' => 0, 'thb' => 0.0],
        ];
    }

    /**
     * @return array{queued_submissions: int, skipped_overridden: int, queued_responses: int, rescored_by_code: int, skipped_in_progress: int, skipped_missing_image: int, reopened_submissions: int}
     *
     * @throws ApiException 409 answer_key_not_approved / regrade_in_progress, 422 exam_manual_grading
     */
    public function run(Assignment $exam, User $teacher, bool $includeOverridden): array
    {
        self::assertRegradable($exam);
        if (self::inProgress($exam)) {
            throw new ApiException('กำลังตรวจใหม่ทั้งห้องอยู่ รอให้ตรวจเสร็จก่อนแล้วลองอีกครั้ง', 'regrade_in_progress', 409);
        }
        $plan = self::plan($exam, $includeOverridden);
        if ($plan['changed'] > 0) {
            Cache::put(self::markerKey($exam->id), now()->toIso8601String(), now()->addMinutes(ClassRegrade::LOST_MINUTES));
            RescoreExamJob::dispatch($exam->id, $teacher->id, $includeOverridden);
        }
        $totals = [
            'queued_submissions' => $plan['submissions'],
            'skipped_overridden' => $plan['skipped_overridden'],
            'queued_responses' => 0,
            'rescored_by_code' => $plan['changed'],
            'skipped_in_progress' => 0,
            'skipped_missing_image' => 0,
            'reopened_submissions' => $plan['published'],
        ];
        Log::info('exams.regrade_queued', ['assignment_id' => $exam->id, 'include_overridden' => $includeOverridden] + $totals);

        return $totals;
    }

    /**
     * RescoreExamJob: writes the new scores, one transaction per submission.
     *
     * @return array{rescored: int, reopened: int}
     */
    public function apply(int $examId, ?int $teacherId, bool $includeOverridden): array
    {
        $out = ['rescored' => 0, 'reopened' => 0];
        try {
            $exam = Assignment::query()->find($examId);
            if ($exam === null || ! $exam->isExam()) {
                return $out;
            }
            $teacher = $teacherId === null ? null : User::query()->find($teacherId);
            $questions = Question::query()->where('assignment_id', $exam->id)->get()->keyBy('id');
            foreach (Submission::query()->where('assignment_id', $exam->id)->orderBy('id')->pluck('id') as $submissionId) {
                [$rescored, $reopened] = DB::transaction(fn () => $this->applyTo((int) $submissionId, $exam, $questions, $teacher, $includeOverridden));
                $out['rescored'] += $rescored;
                $out['reopened'] += $reopened ? 1 : 0;
            }
        } finally {
            Cache::forget(self::markerKey($examId));
        }
        Log::info('exams.regrade_done', ['assignment_id' => $examId] + $out);

        return $out;
    }

    public static function inProgress(Assignment $exam): bool
    {
        $marker = Cache::get(self::markerKey($exam->id));

        return is_string($marker) && Carbon::parse($marker)->gte(now()->subMinutes(ClassRegrade::LOST_MINUTES));
    }

    /**
     * @param  Collection<int, Question>  $questions
     * @return array{0: int, 1: bool}
     */
    private function applyTo(int $submissionId, Assignment $exam, $questions, ?User $teacher, bool $includeOverridden): array
    {
        $submission = Submission::query()->lockForUpdate()->find($submissionId);
        if ($submission === null) {
            return [0, false];
        }
        $responses = Response::query()->where('submission_id', $submissionId)->whereNotNull('exam_answer')->orderBy('id')->lockForUpdate()->get();
        $rescored = 0;
        foreach ($responses as $response) {
            $question = $questions->get($response->question_id);
            if ($question === null || ! self::changes($response, $question, $includeOverridden)) {
                continue;
            }
            self::rescore($response, $question, $teacher);
            $rescored++;
        }
        if ($rescored === 0) {
            return [0, false];
        }
        $wasPublished = $submission->isPublished();
        ExamSheetIngestor::refreshStatus($submission, $exam, reopen: true);
        if ($wasPublished) {
            SubmissionReopened::dispatch($submission->id, $submission->assignment_id, $submission->student_id);
        }

        return [$rescored, $wasPublished];
    }

    /**
     * Would scoring it again change it? Skipped overrides never do.
     */
    private static function changes(Response $response, Question $question, bool $includeOverridden): bool
    {
        $overridden = ClassRegrade::overridden($response);
        if ($overridden && ! $includeOverridden) {
            return false;
        }
        if ($overridden) {
            return true;
        }
        $scored = ExamAnswerScore::of($question, $response->exam_answer);

        return ScoreRules::differs($response->ai_score, $scored['score']) || $response->ai_understanding !== $scored['understanding'];
    }

    private static function rescore(Response $response, Question $question, ?User $teacher): void
    {
        $previous = [$response->effectiveScore(), $response->effectiveUnderstanding()];
        $scored = ExamAnswerScore::of($question, $response->exam_answer);
        $answer = ExamAnswerScore::effective($response->exam_answer);
        $doubtful = array_intersect((array) ($response->exam_answer['doubts'] ?? []), ExamSheetScorer::REVIEW_DOUBTS) !== [];
        $errors = $scored['blank'] ? ['no_answer'] : [];

        $response->forceFill([
            'ai_score' => $scored['score'],
            'ai_understanding' => $scored['understanding'],
            'ai_error_types' => $errors,
        ]);
        if ($answer['resolved'] || ($doubtful && $response->reviewed_by !== null)) {
            // The teacher's reading, or a doubt the teacher already read and
            // kept at the code's score (PATCH, not an override): the new key
            // applies to the same reading and it stays reviewed (§22.11).
            $response->forceFill(['final_score' => $scored['score'], 'final_understanding' => $scored['understanding'], 'final_error_types' => $errors]);
        } elseif ($doubtful) {
            // A doubt the teacher has not read yet goes back to the queue.
            $response->forceFill(['final_score' => null, 'final_understanding' => null, 'final_error_types' => null, 'reviewed_at' => null, 'reviewed_by' => null]);
        } else {
            $response->forceFill([
                'final_score' => $scored['score'],
                'final_understanding' => $scored['understanding'],
                'final_error_types' => $errors,
                'reviewed_at' => now(),
                'reviewed_by' => null,
            ]);
        }
        $response->save();

        if ($previous[0] !== null || $previous[1] !== null) {
            ScoreEvent::create([
                'response_id' => $response->id,
                'actor' => $teacher === null ? ScoreEvent::ACTOR_SYSTEM : ScoreEvent::ACTOR_TEACHER,
                'actor_user_id' => $teacher?->id,
                'action' => ScoreEvent::ACTION_RESCAN,
                'old_score' => $previous[0],
                'new_score' => null,
                'old_understanding' => $previous[1],
                'new_understanding' => null,
                'reason' => ClassRegrade::REASON,
            ]);
        }
        ScoreEvent::create([
            'response_id' => $response->id,
            'actor' => ScoreEvent::ACTOR_SYSTEM,
            'actor_user_id' => null,
            'action' => ScoreEvent::ACTION_AI_SCORED,
            'old_score' => null,
            'new_score' => $scored['score'],
            'old_understanding' => null,
            'new_understanding' => $scored['understanding'],
            'reason' => ClassRegrade::REASON,
        ]);
    }

    /**
     * @return array{submissions: int, changed: int, skipped_overridden: int, published: int}
     */
    private static function plan(Assignment $exam, bool $includeOverridden): array
    {
        $questions = Question::query()->where('assignment_id', $exam->id)->get()->keyBy('id');
        $submissions = Submission::query()->where('assignment_id', $exam->id)->get()->keyBy('id');
        $out = ['submissions' => 0, 'changed' => 0, 'skipped_overridden' => 0, 'published' => 0];
        $touched = [];
        Response::query()
            ->whereIn('submission_id', $submissions->keys())
            ->whereNotNull('exam_answer')
            ->orderBy('id')
            ->each(function (Response $response) use ($questions, $includeOverridden, &$out, &$touched) {
                $question = $questions->get($response->question_id);
                if ($question === null) {
                    return;
                }
                if (! $includeOverridden && ClassRegrade::overridden($response)) {
                    $out['skipped_overridden']++;

                    return;
                }
                if (self::changes($response, $question, $includeOverridden)) {
                    $out['changed']++;
                    $touched[$response->submission_id] = true;
                }
            });
        $out['submissions'] = count($touched);
        foreach (array_keys($touched) as $id) {
            $out['published'] += $submissions->get($id)?->isPublished() ? 1 : 0;
        }

        return $out;
    }

    /** @throws ApiException 422 exam_manual_grading, 409 answer_key_not_approved */
    private static function assertRegradable(Assignment $exam): void
    {
        if ($exam->isManualExam()) {
            throw new ApiException('ข้อสอบที่ครูตรวจเองไม่มีคะแนนจากกระดาษคำตอบให้ตรวจใหม่', 'exam_manual_grading', 422);
        }
        if (! $exam->keyApproved()) {
            throw new ApiException('ยังไม่ได้อนุมัติเฉลยของข้อสอบนี้ อนุมัติเฉลยก่อนจึงจะตรวจใหม่ได้', 'answer_key_not_approved', 409);
        }
    }

    private static function markerKey(int $examId): string
    {
        return 'exam-regrade:'.$examId;
    }
}
