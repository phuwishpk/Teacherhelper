<?php

namespace App\Domain\Grading;

use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\CropMissing;
use App\Domain\Gemini\ExplanationRequests;
use App\Domain\Gemini\ExtractionRequests;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\RubricDraftRequest;
use App\Domain\Notifications\GradingNotices;
use App\Domain\Review\ReviewFlags;
use App\Domain\Scans\SubmissionStatus;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Response;
use App\Models\Scan;
use App\Models\ScoreEvent;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The grading pipeline behind GradeScanJob (DESIGN §7.2):
 *
 * 1. the scan's responses that are `queued`, or `failed` with attempts < 3;
 * 2. the key per §10.1 (teacher, then server); none -> `manual`, ai_key_missing;
 * 3. `extract` for all of them through GeminiGateway (Http::pool, 8 at a
 *    time; invalid output retried once);
 * 4. fuzzy systems 1 and 2 (ResponseGrader);
 * 5. `explanation` (text only) for answers below full marks, template praise
 *    for full marks, a template for blank answers; none for suspicious ones
 *    (the teacher must look at those first anyway). An explanation that
 *    fails (after the gateway's one retry) leaves the score standing and
 *    records fuzzy_trace.explanation_error = the call status, so the review
 *    queue can offer "regenerate explanation" before publishing;
 * 6. one transaction under the submission lock writes the results, logs
 *    `ai_scored`, and counts failures: attempts++, `failed` while attempts < 3,
 *    then `manual`. A response a rescan moved to another scan meanwhile is
 *    left alone. Every new fuzzy_trace keeps the sticky flags set at ingest
 *    (ReviewFlags::carry, e.g. identity_mismatch);
 * 7. GradingNotices tells the teacher once nothing of the assignment is left
 *    to grade (at most once per cooldown, not once per scan).
 *
 * Gemini runs outside the transaction; only the final write holds locks.
 */
final class ScanGrader
{
    public const MAX_ATTEMPTS = 3;

    /** Manual reasons (responses.fuzzy_trace.manual_reason). */
    public const REASON_KEY_MISSING = 'ai_key_missing';

    public const REASON_KEY_INVALID = 'ai_key_invalid';

    public const REASON_AI_ERROR = 'ai_error';

    public const REASON_INVALID_OUTPUT = 'invalid_output';

    public const REASON_CROP_MISSING = 'crop_missing';

    public const REASON_RUBRIC_MISSING = 'rubric_missing';

    public const REASON_NOT_GRADABLE = 'not_gradable';

    /** Reasons a new or fixed API key resolves (POST /assignments/{id}/requeue-missing-key). */
    public const KEY_REASONS = [self::REASON_KEY_MISSING, self::REASON_KEY_INVALID];

    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly GeminiKeyResolver $keys,
        private readonly ExtractionRequests $extractions,
        private readonly ExplanationRequests $explanations,
        private readonly GradingNotices $notices,
    ) {}

    public function grade(int $scanId): ScanGradingResult
    {
        $scan = Scan::query()->with(['submission.assignment.classroom', 'submission.assignment.subject'])->find($scanId);
        $assignment = $scan?->submission?->assignment;
        if ($scan === null || $assignment === null) {
            return new ScanGradingResult;
        }

        /** @var Collection<int, Response> $responses */
        $responses = Response::query()
            ->where('scan_id', $scan->id)
            ->whereIn('grading_state', [Response::STATE_QUEUED, Response::STATE_FAILED])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->with(['question.rubricCriteria'])
            ->orderBy('id')
            ->get();
        if ($responses->isEmpty()) {
            return new ScanGradingResult;
        }

        $key = $this->keys->forTeacher($assignment->classroom?->teacher_id);
        if ($key === null) {
            $manual = $responses->mapWithKeys(fn (Response $r) => [$r->id => self::REASON_KEY_MISSING])->all();

            return $this->write($scan, $assignment, $responses, [], [], [], $manual);
        }

        $gradeLabel = RubricDraftRequest::gradeLabel((int) $assignment->classroom->grade_level);
        $subject = (string) $assignment->subject?->name;

        $calls = [];
        $manual = [];
        foreach ($responses as $response) {
            $question = $response->question;
            $criteria = $question->rubricCriteria->all();
            if ($question->type === Question::TYPE_MCQ) {
                $manual[$response->id] = self::REASON_NOT_GRADABLE;

                continue;
            }
            if ($question->type === Question::TYPE_OPEN && $criteria === []) {
                $manual[$response->id] = self::REASON_RUBRIC_MISSING;

                continue;
            }
            try {
                $calls[$response->id] = $this->extractions->forResponse($response, $question, $criteria, $subject, $gradeLabel);
            } catch (CropMissing) {
                $manual[$response->id] = self::REASON_CROP_MISSING;
            }
        }

        $outcomes = $calls === [] ? [] : $this->gateway->run($calls, $key);

        $graded = [];
        foreach ($outcomes as $id => $outcome) {
            if (! $outcome->isOk()) {
                continue;
            }
            $response = $responses->firstWhere('id', $id);
            $graded[$id] = ResponseGrader::grade(
                $response->question,
                $response->question->rubricCriteria->all(),
                $assignment->strictness,
                (array) $outcome->data,
                $response->cnn_text,
                $response->ink_ratio,
            );
        }

        $explanations = [];
        $explainCalls = [];
        foreach ($graded as $id => $grade) {
            $response = $responses->firstWhere('id', $id);
            $max = (float) $response->question->max_points;
            if (! $grade->isScored()) {
                continue;
            }
            if ($grade->score !== null && $grade->score >= $max) {
                $explanations[$id] = FeedbackTemplates::praise($id);
            } elseif ($grade->blank) {
                $explanations[$id] = FeedbackTemplates::BLANK;
            } elseif (! $grade->suspicious) {
                $explainCalls[$id] = $this->explanations->forResponse(
                    $response,
                    $response->question,
                    $response->question->rubricCriteria->all(),
                    $gradeLabel,
                    (array) $outcomes[$id]->data,
                );
            }
        }
        $explanationErrors = [];
        if ($explainCalls !== []) {
            $explained = $this->gateway->run($explainCalls, $key);
            foreach (array_keys($explainCalls) as $id) {
                $outcome = $explained[$id] ?? null;
                if ($outcome?->isOk()) {
                    $explanations[$id] = ExplanationRequests::text((array) $outcome->data);
                } else {
                    $explanationErrors[$id] = $outcome->status ?? CallOutcome::ERROR;
                }
            }
        }

        return $this->write($scan, $assignment, $responses, $outcomes, $graded, $explanations, $manual, $explanationErrors);
    }

    /**
     * After the job ran out of tries: whatever is still in progress for this
     * scan goes to the teacher as `manual` / ai_error.
     */
    public function giveUp(int $scanId): void
    {
        $scan = Scan::query()->with('submission.assignment.classroom')->find($scanId);
        $assignment = $scan?->submission?->assignment;
        if ($scan === null || $assignment === null) {
            return;
        }
        $responses = Response::query()
            ->where('scan_id', $scan->id)
            ->whereIn('grading_state', Response::IN_PROGRESS_STATES)
            ->get();
        if ($responses->isNotEmpty()) {
            $manual = $responses->mapWithKeys(fn (Response $r) => [$r->id => self::REASON_AI_ERROR])->all();
            $this->write($scan, $assignment, $responses, [], [], [], $manual, anyState: true);
        }
    }

    /**
     * @param  Collection<int, Response>  $responses  as loaded before calling Gemini
     * @param  array<int, CallOutcome>  $outcomes
     * @param  array<int, GradeOutcome>  $graded
     * @param  array<int, string>  $explanations
     * @param  array<int, string>  $manual  response id => manual reason
     * @param  array<int, string>  $explanationErrors  response id => status of the failed explanation call
     */
    private function write(
        Scan $scan,
        Assignment $assignment,
        Collection $responses,
        array $outcomes,
        array $graded,
        array $explanations,
        array $manual,
        array $explanationErrors = [],
        bool $anyState = false,
    ): ScanGradingResult {
        $counts = DB::transaction(function () use ($scan, $responses, $outcomes, $graded, $explanations, $manual, $explanationErrors, $anyState) {
            $submission = Submission::query()->lockForUpdate()->find($scan->submission_id);
            $counts = ['scored' => 0, 'failed' => 0, 'manual' => 0, 'skipped' => 0];

            foreach ($responses as $loaded) {
                $response = Response::query()->lockForUpdate()->find($loaded->id);
                $allowed = $anyState ? Response::IN_PROGRESS_STATES : [Response::STATE_QUEUED, Response::STATE_FAILED];
                if ($response === null
                    || $response->scan_id !== $scan->id
                    || ! in_array($response->grading_state, $allowed, true)
                    || $response->attempts !== $loaded->attempts) {
                    $counts['skipped']++; // rescanned or requeued while Gemini was working

                    continue;
                }

                if (isset($manual[$response->id])) {
                    self::markManual($response, $manual[$response->id]);
                    $counts['manual']++;
                } elseif (isset($graded[$response->id])) {
                    $state = $this->applyGrade(
                        $response,
                        $graded[$response->id],
                        (array) $outcomes[$response->id]->data,
                        $explanations[$response->id] ?? null,
                        $explanationErrors[$response->id] ?? null,
                    );
                    $counts[$state === Response::STATE_SCORED ? 'scored' : 'manual']++;
                } elseif (isset($outcomes[$response->id])) {
                    $state = self::applyFailure($response, $outcomes[$response->id]);
                    $counts[$state === Response::STATE_FAILED ? 'failed' : 'manual']++;
                } else {
                    $counts['skipped']++;
                }
            }

            if ($submission !== null) {
                SubmissionStatus::refresh($submission);
            }

            return $counts;
        });

        if ($counts['scored'] + $counts['manual'] > 0) {
            $this->notices->answersFinished($assignment);
        }

        return new ScanGradingResult($counts['scored'], $counts['failed'], $counts['manual'], $counts['skipped'], $counts['failed'] > 0);
    }

    private function applyGrade(Response $response, GradeOutcome $grade, array $extraction, ?string $explanation, ?string $explanationError): string
    {
        $trace = $grade->trace;
        if ($explanationError !== null && ! $response->explanation_edited) {
            $trace['explanation_error'] = $explanationError; // the teacher's own text stays; nothing is missing then
        }
        $response->forceFill([
            'grading_state' => $grade->isScored() ? Response::STATE_SCORED : Response::STATE_MANUAL,
            'extraction' => $extraction,
            'fuzzy_trace' => ReviewFlags::carry($response->fuzzy_trace, $trace),
            'ai_score' => $grade->score,
            'ai_understanding' => $grade->understanding,
            'ai_error_types' => $grade->errorTypes,
            'review_priority' => $grade->reviewPriority,
            'priority_band' => $grade->priorityBand,
        ]);
        if (! $response->explanation_edited) {
            $response->explanation = $explanation;
        }
        $response->save();

        if ($grade->isScored()) {
            ScoreEvent::create([
                'response_id' => $response->id,
                'actor' => ScoreEvent::ACTOR_AI,
                'actor_user_id' => null,
                'action' => ScoreEvent::ACTION_AI_SCORED,
                'old_score' => null,
                'new_score' => $grade->score,
                'old_understanding' => null,
                'new_understanding' => $grade->understanding,
                'reason' => null,
            ]);
        }

        return $response->grading_state;
    }

    private static function applyFailure(Response $response, CallOutcome $outcome): string
    {
        $response->attempts++;
        if ($outcome->status === CallOutcome::KEY_INVALID) {
            self::markManual($response, self::REASON_KEY_INVALID, $outcome->status);

            return Response::STATE_MANUAL;
        }
        if ($response->attempts >= self::MAX_ATTEMPTS) {
            $reason = $outcome->status === CallOutcome::INVALID_OUTPUT ? self::REASON_INVALID_OUTPUT : self::REASON_AI_ERROR;
            self::markManual($response, $reason, $outcome->status);

            return Response::STATE_MANUAL;
        }

        $response->grading_state = Response::STATE_FAILED;
        $response->fuzzy_trace = ReviewFlags::carry($response->fuzzy_trace, ['last_error' => $outcome->status]);
        $response->save();

        return Response::STATE_FAILED;
    }

    private static function markManual(Response $response, string $reason, ?string $lastError = null): void
    {
        $priority = ReviewPriority::manual();
        $response->forceFill([
            'grading_state' => Response::STATE_MANUAL,
            'fuzzy_trace' => ReviewFlags::carry($response->fuzzy_trace, array_filter(['manual_reason' => $reason, 'last_error' => $lastError])),
            'review_priority' => $priority->storedP(),
            'priority_band' => $priority->band,
        ])->save();
    }
}
