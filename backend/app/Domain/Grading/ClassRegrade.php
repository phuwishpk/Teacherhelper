<?php

namespace App\Domain\Grading;

use App\Domain\Documents\CostEstimate;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\MediaResolution;
use App\Domain\Pages\PageFiles;
use App\Domain\Pages\WholePageSubmissions;
use App\Domain\Review\ReviewFlags;
use App\Domain\Review\ScoreRules;
use App\Domain\Scans\ResponseWriter;
use App\Domain\Scans\ScanFiles;
use App\Domain\Scans\SubmissionStatus;
use App\Events\SubmissionReopened;
use App\Exceptions\ApiException;
use App\Jobs\GradeScanJob;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\SubmissionPage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "ตรวจใหม่ทั้งห้อง" after the answer key changed (DESIGN §21.13):
 * POST /assignments/{id}/regrade and its free estimate.
 *
 * Every submission graded at least once (a response `scored` or `manual`)
 * is graded again with the current approved key (409
 * answer_key_not_approved without one), answer by answer:
 *
 * - mcq of the crop path: scored again by code from the stored bubble fill
 *   (McqGrader, §11.6), no Gemini; an answer whose score and understanding
 *   do not change is left as it is (reviewed stays reviewed) and is not
 *   counted in the estimate or the outcome;
 * - short / show_work / open of the crop path: reset to `queued` (crops,
 *   the phone's readings and the sticky review flags kept) and read again
 *   by GradeScanJob, one job per scan;
 * - whole-page answers: reset to `queued` and the pages of the current
 *   round read again (WholePageSubmissions::restartCurrentRound, one
 *   GradeSubmissionPageJob per page); the merge writes only the reset
 *   answers, so skipped ones keep their score;
 * - skipped: answers the teacher overrode (a final score or understanding
 *   of their own, graded by hand, or changed by an appeal) unless
 *   include_overridden; answers still being graded (they already read the
 *   current key); answers whose crop or page file was purged
 *   (skipped_missing_image: grading them again would only turn them into
 *   `manual`).
 *
 * A reset answer that had a score logs a `rescan` score event (actor
 * teacher, reason "answer key changed: class regrade"); a changed mcq also
 * logs `ai_scored`. A published submission with any changed answer is
 * reopened exactly like a confirmed rescan (SubmissionStatus::refresh with
 * reopen, SubmissionReopened: mastery drops its observations until the
 * next publish, which also posts the Classroom feedback and grade again).
 * Explanations are not carried over: ExplanationCache's fingerprint holds
 * the key, so texts written against the old key are never reused.
 *
 * Idempotency: a run that queued jobs leaves a marker in the cache; while
 * it is younger than LOST_MINUTES and anything of the assignment is still
 * being graded, another run answers 409 regrade_in_progress (the estimate
 * reports in_progress). Without a run of its own in flight, answers that
 * are being graded (a new scan, say) are skipped: their job reads the
 * current key already. An answer still `queued` whose last change is older
 * than LOST_MINUTES was lost and is reset like the others.
 */
final class ClassRegrade
{
    /** A run whose work is still in progress after this long is presumed lost. */
    public const LOST_MINUTES = 30;

    public const REASON = 'answer key changed: class regrade';

    /** Estimate: text of one crop answer's share of a call (question, key, instructions). */
    private const CROP_PROMPT_TOKENS = 400;

    private const EXPLANATION_INPUT = 600;

    private const EXPLANATION_OUTPUT = 200;

    private const KIND_IN_PROGRESS = 'in_progress';

    private const KIND_OVERRIDDEN = 'overridden';

    private const KIND_MISSING_IMAGE = 'missing_image';

    private const KIND_MCQ = 'mcq';

    private const KIND_CROP = 'crop';

    private const KIND_PAGE = 'page';

    private const KIND_NONE = 'none';

    public function __construct(private readonly GeminiKeyResolver $keys) {}

    /**
     * POST /assignments/{id}/regrade/estimate: what a run would do and cost.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 409 answer_key_not_approved
     */
    public function estimate(Assignment $assignment, bool $includeOverridden): array
    {
        self::assertApproved($assignment);
        $plan = $this->plan($assignment, $includeOverridden);

        return self::counts($plan) + [
            'in_progress' => $this->inProgress($assignment),
            'estimate' => self::cost($plan),
        ];
    }

    /**
     * POST /assignments/{id}/regrade.
     *
     * @return array{queued_submissions: int, skipped_overridden: int, queued_responses: int, rescored_by_code: int, skipped_in_progress: int, skipped_missing_image: int, reopened_submissions: int}
     *
     * @throws ApiException 409 answer_key_not_approved / regrade_in_progress, 422 ai_key_missing
     */
    public function run(Assignment $assignment, User $teacher, bool $includeOverridden): array
    {
        $assignment = Assignment::query()->with('classroom')->findOrFail($assignment->id);
        self::assertApproved($assignment);
        if ($this->inProgress($assignment)) {
            throw new ApiException('กำลังตรวจใหม่ทั้งห้องอยู่ รอให้ตรวจเสร็จก่อนแล้วลองอีกครั้ง', 'regrade_in_progress', 409);
        }

        $plan = $this->plan($assignment, $includeOverridden);
        $needsGemini = collect($plan)->contains(fn (array $s) => in_array(self::KIND_CROP, $s['kinds'], true) || in_array(self::KIND_PAGE, $s['kinds'], true));
        if ($needsGemini && $this->keys->forTeacher($assignment->classroom?->teacher_id) === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }

        $totals = [
            'queued_submissions' => 0,
            'skipped_overridden' => 0,
            'queued_responses' => 0,
            'rescored_by_code' => 0,
            'skipped_in_progress' => 0,
            'skipped_missing_image' => 0,
            'reopened_submissions' => 0,
        ];
        $marked = false;
        foreach ($plan as $submissionId => $entry) {
            $outcome = DB::transaction(fn () => $this->applyTo($submissionId, $entry['pages'], $teacher, $includeOverridden));
            if ($outcome === null) {
                continue;
            }
            foreach (['skipped_overridden', 'queued_responses', 'rescored_by_code', 'skipped_in_progress', 'skipped_missing_image'] as $k) {
                $totals[$k] += $outcome[$k];
            }
            if ($outcome['changed']) {
                $totals['queued_submissions']++;
                $totals['reopened_submissions'] += $outcome['reopened'] ? 1 : 0;
            }
            if ($outcome['scan_ids'] === [] && $outcome['page_ids'] === []) {
                continue;
            }
            // Right after this submission's commit (a scan and its pages belong to one submission): a run cut
            // short later (an error, the host's time limit) never leaves committed `queued` answers without a job.
            if (! $marked) {
                Cache::put(self::markerKey($assignment->id), now()->toIso8601String(), now()->addMinutes(self::LOST_MINUTES));
                $marked = true;
            }
            foreach ($outcome['scan_ids'] as $scanId) {
                GradeScanJob::dispatch($scanId);
            }
            WholePageSubmissions::dispatch($outcome['page_ids']);
        }

        Log::info('grading.class_regrade', ['assignment_id' => $assignment->id, 'include_overridden' => $includeOverridden] + $totals);

        return $totals;
    }

    /**
     * True while a run's jobs are still working: its marker is younger than
     * LOST_MINUTES and an answer of the assignment is still queued or being
     * read, or a whole page is still being read.
     */
    public function inProgress(Assignment $assignment): bool
    {
        $marker = Cache::get(self::markerKey($assignment->id));
        if (! is_string($marker) || Carbon::parse($marker)->lt(now()->subMinutes(self::LOST_MINUTES))) {
            return false;
        }
        $since = now()->subMinutes(self::LOST_MINUTES);
        $submissions = Submission::query()->select('id')->where('assignment_id', $assignment->id);

        return Response::query()
            ->whereIn('submission_id', $submissions)
            ->whereIn('grading_state', Response::IN_PROGRESS_STATES)
            ->where('updated_at', '>=', $since)
            ->exists()
            || SubmissionPage::query()
                ->whereIn('submission_id', $submissions)
                ->where('state', SubmissionPage::STATE_GRADING)
                ->where('updated_at', '>=', $since)
                ->exists();
    }

    /**
     * An answer the teacher decided: a final score or understanding of
     * their own (an override, a manual grade, an accepted appeal), not a
     * bulk approval of the AI's values.
     */
    public static function overridden(Response $response): bool
    {
        if ($response->final_score === null && $response->final_understanding === null) {
            return false;
        }
        if ($response->ai_score === null && $response->ai_understanding === null) {
            return true;
        }

        return ScoreRules::differs($response->ai_score, $response->final_score)
            || ($response->final_understanding !== null && $response->final_understanding !== $response->ai_understanding);
    }

    /**
     * Per submission graded at least once: the kind of every answer, and
     * the state of its current whole-page round.
     *
     * @return array<int, array{kinds: array<int, string>, responses: array<int, Response>, pages: array{busy: bool, missing: bool, count: int, page_images: int}}>
     */
    private function plan(Assignment $assignment, bool $includeOverridden): array
    {
        $submissions = Submission::query()
            ->where('assignment_id', $assignment->id)
            ->whereHas('responses', fn ($q) => $q->whereIn('grading_state', [Response::STATE_SCORED, Response::STATE_MANUAL]))
            ->with(['responses.question', 'pages' => fn ($q) => $q->whereIn('state', SubmissionPage::CURRENT_STATES)])
            ->orderBy('id')
            ->get();

        $plan = [];
        foreach ($submissions as $submission) {
            $pages = self::pagesOf($submission->pages);
            $kinds = [];
            $responses = [];
            foreach ($submission->responses as $response) {
                $kinds[$response->id] = self::classify($response, $response->question, $pages, $includeOverridden);
                $responses[$response->id] = $response;
            }
            $plan[$submission->id] = ['kinds' => $kinds, 'responses' => $responses, 'pages' => $pages];
        }

        return $plan;
    }

    /**
     * @param  Collection<int, SubmissionPage>  $pages  the current round
     * @return array{busy: bool, missing: bool, count: int, page_images: int}
     */
    private static function pagesOf(Collection $pages): array
    {
        $disk = PageFiles::disk();

        return [
            'busy' => $pages->contains(fn (SubmissionPage $p) => $p->state === SubmissionPage::STATE_GRADING
                && $p->updated_at !== null && $p->updated_at->gte(now()->subMinutes(self::LOST_MINUTES))),
            'missing' => $pages->isEmpty() || $pages->contains(fn (SubmissionPage $p) => $p->file_path === null || ! $disk->exists($p->file_path)),
            'count' => $pages->count(),
            'page_images' => (int) $pages->sum(fn (SubmissionPage $p) => max(1, (int) $p->page_count)),
        ];
    }

    /**
     * @param  array{busy: bool, missing: bool, count: int, page_images: int}  $pages
     */
    private static function classify(Response $response, ?Question $question, array $pages, bool $includeOverridden): string
    {
        if ($question === null) {
            return self::KIND_NONE;
        }
        if (in_array($response->grading_state, Response::IN_PROGRESS_STATES, true)) {
            $lost = $response->updated_at !== null && $response->updated_at->lt(now()->subMinutes(self::LOST_MINUTES));
            if (! $lost) {
                return self::KIND_IN_PROGRESS;
            }
        }
        if (! $includeOverridden && self::overridden($response)) {
            return self::KIND_OVERRIDDEN;
        }

        if ($response->scan_id !== null) {
            $isMcq = $question->type === Question::TYPE_MCQ;
            $hasFill = is_array($response->mcq_fill) && $response->mcq_fill !== [];
            if ($isMcq !== $hasFill) {
                return self::KIND_NONE; // the printed kind no longer fits the question: stays manual (layout_type_mismatch)
            }
            if ($isMcq) {
                return self::mcqUnchanged($response, $question) ? self::KIND_NONE : self::KIND_MCQ;
            }
            $disk = ScanFiles::disk();
            if ($response->crop_path === null || ! $disk->exists($response->crop_path)) {
                return self::KIND_MISSING_IMAGE;
            }

            return self::KIND_CROP;
        }

        if ($response->submission_page_id !== null) {
            if ($pages['missing']) {
                return self::KIND_MISSING_IMAGE;
            }

            return $pages['busy'] ? self::KIND_IN_PROGRESS : self::KIND_PAGE;
        }

        return self::KIND_NONE;
    }

    /**
     * Runs in a transaction: locks the submission and its answers, applies
     * the plan again on the locked rows.
     *
     * @param  array{busy: bool, missing: bool, count: int, page_images: int}  $pages
     * @return array{changed: bool, reopened: bool, scan_ids: list<int>, page_ids: list<int>, skipped_overridden: int, queued_responses: int, rescored_by_code: int, skipped_in_progress: int, skipped_missing_image: int}|null
     */
    private function applyTo(int $submissionId, array $pages, User $teacher, bool $includeOverridden): ?array
    {
        $submission = Submission::query()->lockForUpdate()->find($submissionId);
        if ($submission === null) {
            return null;
        }
        $responses = Response::query()->where('submission_id', $submissionId)->with('question')->orderBy('id')->lockForUpdate()->get();

        $out = ['changed' => false, 'reopened' => false, 'scan_ids' => [], 'page_ids' => [], 'skipped_overridden' => 0, 'queued_responses' => 0, 'rescored_by_code' => 0, 'skipped_in_progress' => 0, 'skipped_missing_image' => 0];
        $restartPages = false;
        foreach ($responses as $response) {
            switch (self::classify($response, $response->question, $pages, $includeOverridden)) {
                case self::KIND_OVERRIDDEN:
                    $out['skipped_overridden']++;
                    break;
                case self::KIND_IN_PROGRESS:
                    $out['skipped_in_progress']++;
                    break;
                case self::KIND_MISSING_IMAGE:
                    $out['skipped_missing_image']++;
                    break;
                case self::KIND_MCQ:
                    if (self::rescoreMcq($response, $response->question, $teacher)) {
                        $out['rescored_by_code']++;
                        $out['changed'] = true;
                    }
                    break;
                case self::KIND_CROP:
                    self::requeue($response, $teacher);
                    $out['scan_ids'][] = (int) $response->scan_id;
                    $out['queued_responses']++;
                    $out['changed'] = true;
                    break;
                case self::KIND_PAGE:
                    self::requeue($response, $teacher);
                    $restartPages = true;
                    $out['queued_responses']++;
                    $out['changed'] = true;
                    break;
            }
        }

        if ($restartPages) {
            $out['page_ids'] = array_map('intval', WholePageSubmissions::restartCurrentRound($submission));
        }
        if ($out['changed']) {
            $wasPublished = $submission->isPublished();
            SubmissionStatus::refresh($submission, reopen: true);
            if ($wasPublished) {
                // After the commit (ShouldDispatchAfterCommit): mastery drops the rows of the reopened submission (§14.2).
                SubmissionReopened::dispatch($submission->id, $submission->assignment_id, $submission->student_id);
                $out['reopened'] = true;
            }
        }
        $out['scan_ids'] = array_values(array_unique($out['scan_ids']));

        return $out;
    }

    /** Back to `queued` for Gemini; crops, readings and sticky flags stay. */
    private static function requeue(Response $response, User $teacher): void
    {
        $previous = self::previous($response);
        $trace = $response->fuzzy_trace;
        $response->fill(ResponseWriter::RESET);
        $response->fuzzy_trace = ReviewFlags::carry($trace, null);
        $response->save();
        self::logReset($response, $teacher, $previous);
    }

    /**
     * Scoring the mcq answer again by code would leave it as it is: the
     * same score and understanding (or still no usable key option), and no
     * teacher decision to replace. Such an answer is not counted, so the
     * estimate of an unchanged key says there is nothing to do.
     */
    private static function mcqUnchanged(Response $response, Question $question): bool
    {
        if (self::overridden($response)) {
            return false;
        }
        $fill = (array) $response->mcq_fill;
        $correct = $question->answer_key['correct'] ?? null;
        if (! is_string($correct) || ! array_key_exists($correct, $fill)) {
            return $response->manualReason() === 'answer_key_missing';
        }
        $grade = McqGrader::grade(array_map('floatval', $fill), $correct, (float) $question->max_points);

        return $response->grading_state === Response::STATE_SCORED
            && ! ScoreRules::differs($response->ai_score, $grade->score)
            && $response->ai_understanding === $grade->understanding;
    }

    /**
     * The mcq answer scored again by code; false when nothing changed.
     */
    private static function rescoreMcq(Response $response, Question $question, User $teacher): bool
    {
        if (self::mcqUnchanged($response, $question)) {
            return false;
        }
        $fill = (array) $response->mcq_fill;
        $correct = $question->answer_key['correct'] ?? null;

        if (! is_string($correct) || ! array_key_exists($correct, $fill)) {
            $previous = self::previous($response);
            $trace = $response->fuzzy_trace;
            $response->fill(ResponseWriter::RESET);
            $response->forceFill(['grading_state' => Response::STATE_MANUAL, 'fuzzy_trace' => ReviewFlags::carry($trace, ['manual_reason' => 'answer_key_missing'])]);
            $response->save();
            self::logReset($response, $teacher, $previous);

            return true;
        }

        $grade = McqGrader::grade(array_map('floatval', $fill), $correct, (float) $question->max_points);
        $previous = self::previous($response);
        $trace = $response->fuzzy_trace;
        $response->fill(ResponseWriter::RESET);
        $response->forceFill([
            'grading_state' => Response::STATE_SCORED,
            'ai_score' => $grade->score,
            'ai_understanding' => $grade->understanding,
            'ai_error_types' => $grade->errorTypes,
            'review_priority' => $grade->reviewPriority,
            'priority_band' => $grade->priorityBand,
            'fuzzy_trace' => ReviewFlags::carry($trace, $grade->trace),
        ]);
        $response->save();
        self::logReset($response, $teacher, $previous);
        ScoreEvent::create([
            'response_id' => $response->id,
            'actor' => ScoreEvent::ACTOR_SYSTEM,
            'actor_user_id' => null,
            'action' => ScoreEvent::ACTION_AI_SCORED,
            'old_score' => null,
            'new_score' => $response->ai_score,
            'old_understanding' => null,
            'new_understanding' => $response->ai_understanding,
            'reason' => self::REASON,
        ]);

        return true;
    }

    /** @return array{0: float|null, 1: string|null}|null */
    private static function previous(Response $response): ?array
    {
        if ($response->effectiveScore() === null && $response->effectiveUnderstanding() === null) {
            return null;
        }

        return [$response->effectiveScore(), $response->effectiveUnderstanding()];
    }

    /** @param array{0: float|null, 1: string|null}|null $previous */
    private static function logReset(Response $response, User $teacher, ?array $previous): void
    {
        if ($previous === null) {
            return;
        }
        ScoreEvent::create([
            'response_id' => $response->id,
            'actor' => ScoreEvent::ACTOR_TEACHER,
            'actor_user_id' => $teacher->id,
            'action' => ScoreEvent::ACTION_RESCAN,
            'old_score' => $previous[0],
            'new_score' => null,
            'old_understanding' => $previous[1],
            'new_understanding' => null,
            'reason' => self::REASON,
        ]);
    }

    /**
     * @param  array<int, array{kinds: array<int, string>, responses: array<int, Response>, pages: array{busy: bool, missing: bool, count: int, page_images: int}}>  $plan
     * @return array{submissions: int, queued_responses: int, mcq_by_code: int, whole_page_pages: int, skipped_overridden: int, skipped_in_progress: int, skipped_missing_image: int, published_submissions: int}
     */
    private static function counts(array $plan): array
    {
        $count = fn (string $kind) => array_sum(array_map(fn (array $s) => count(array_keys($s['kinds'], $kind, true)), $plan));
        $touched = array_filter($plan, fn (array $s) => array_intersect($s['kinds'], [self::KIND_MCQ, self::KIND_CROP, self::KIND_PAGE]) !== []);
        $published = Submission::query()->whereIn('id', array_keys($touched) ?: [0])->where('status', Submission::STATUS_PUBLISHED)->count();

        return [
            'submissions' => count($touched),
            'queued_responses' => $count(self::KIND_CROP) + $count(self::KIND_PAGE),
            'mcq_by_code' => $count(self::KIND_MCQ),
            'whole_page_pages' => array_sum(array_map(fn (array $s) => in_array(self::KIND_PAGE, $s['kinds'], true) ? $s['pages']['count'] : 0, $plan)),
            'skipped_overridden' => $count(self::KIND_OVERRIDDEN),
            'skipped_in_progress' => $count(self::KIND_IN_PROGRESS),
            'skipped_missing_image' => $count(self::KIND_MISSING_IMAGE),
            'published_submissions' => $published,
        ];
    }

    /**
     * An upper bound in the conventions of CostEstimate: a crop answer is
     * its images at their part's media resolution plus CROP_PROMPT_TOKENS in,
     * OUTPUT_PER_QUESTION out; a whole-page round is every page image at the
     * page resolution plus PROMPT_TOKENS per file in, OUTPUT_PER_QUESTION per
     * question out; and every re-read answer is counted as needing an
     * explanation (text only), as if none got full marks. mcq by code is free.
     *
     * @param  array<int, array{kinds: array<int, string>, responses: array<int, Response>, pages: array{busy: bool, missing: bool, count: int, page_images: int}}>  $plan
     * @return array{input_tokens: int, output_tokens: int, thb: float|null}
     */
    private static function cost(array $plan): array
    {
        $input = 0;
        $output = 0;
        foreach ($plan as $entry) {
            $pageQuestions = 0;
            foreach ($entry['kinds'] as $id => $kind) {
                if ($kind === self::KIND_CROP) {
                    $question = $entry['responses'][$id]->question;
                    $input += CostEstimate::tokensForPart($question?->type === Question::TYPE_SHORT ? MediaResolution::PART_SHORT : MediaResolution::PART_WORK)
                        + ($question?->type === Question::TYPE_SHOW_WORK && $entry['responses'][$id]->final_crop_path !== null ? CostEstimate::tokensForPart(MediaResolution::PART_SHORT) : 0)
                        + self::CROP_PROMPT_TOKENS;
                    $output += CostEstimate::OUTPUT_PER_QUESTION;
                } elseif ($kind === self::KIND_PAGE) {
                    $pageQuestions++;
                } else {
                    continue;
                }
                if ($entry['responses'][$id]->question?->type !== Question::TYPE_MCQ) {
                    $input += self::EXPLANATION_INPUT;
                    $output += self::EXPLANATION_OUTPUT;
                }
            }
            if ($pageQuestions > 0) {
                $input += $entry['pages']['page_images'] * CostEstimate::tokensForPart(MediaResolution::PART_PAGE) + $entry['pages']['count'] * CostEstimate::PROMPT_TOKENS;
                $output += $entry['pages']['count'] * count($entry['kinds']) * CostEstimate::OUTPUT_PER_QUESTION;
            }
        }

        return ['input_tokens' => $input, 'output_tokens' => $output, 'thb' => CostEstimate::thb($input, $output)];
    }

    /** @throws ApiException 409 answer_key_not_approved */
    private static function assertApproved(Assignment $assignment): void
    {
        if (! $assignment->keyApproved()) {
            throw new ApiException('ยังไม่ได้อนุมัติเฉลยของการบ้านนี้ อนุมัติเฉลยก่อนจึงจะตรวจใหม่ได้', 'answer_key_not_approved', 409);
        }
    }

    private static function markerKey(int $assignmentId): string
    {
        return 'class-regrade:'.$assignmentId;
    }
}
