<?php

namespace App\Domain\Grading;

use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\CropMissing;
use App\Domain\Gemini\ExtractionRequests;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\RubricDraftRequest;
use App\Domain\Notifications\GradingNotices;
use App\Domain\Review\ReviewFlags;
use App\Domain\Scans\SubmissionStatus;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Response;
use App\Models\Scan;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The grading pipeline behind GradeScanJob (DESIGN §7.2):
 *
 * 1. the scan's responses that are `queued`, or `failed` with attempts < 3;
 * 2. the key per §10.1 (teacher, then server); none -> `manual`, ai_key_missing;
 * 3. extraction through CropExtractor: one `extract_batch` call for the
 *    page's answers, then `extract` alone for any answer the batch missed
 *    or got wrong (DESIGN §21.4; invalid output retried once by the gateway);
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
        private readonly GeminiKeyResolver $keys,
        private readonly CropExtractor $extractor,
        private readonly GradeApplier $applier,
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

        $items = [];
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
                [$crop, $final] = ExtractionRequests::crops($response, $question);
                $items[$response->id] = ['response' => $response, 'question' => $question, 'criteria' => $criteria, 'crop' => $crop, 'final' => $final];
            } catch (CropMissing) {
                $manual[$response->id] = self::REASON_CROP_MISSING;
            }
        }

        $outcomes = $this->extractor->extract($items, $key, $subject, $gradeLabel, $assignment->id);

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

        [$explanations, $explanationErrors] = $this->applier->explain(
            $graded,
            $responses->keyBy('id')->all(),
            array_map(fn (CallOutcome $o) => (array) $o->data, array_intersect_key($outcomes, $graded)),
            $key,
            $gradeLabel,
        );

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
     * @param  array<int, array{text: string, source: string}>  $explanations
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
                    GradeApplier::markManual($response, $manual[$response->id]);
                    $counts['manual']++;
                } elseif (isset($graded[$response->id])) {
                    $state = GradeApplier::applyGrade(
                        $response,
                        $graded[$response->id],
                        (array) $outcomes[$response->id]->data,
                        $explanations[$response->id] ?? null,
                        $explanationErrors[$response->id] ?? null,
                    );
                    $counts[$state === Response::STATE_SCORED ? 'scored' : 'manual']++;
                } elseif (isset($outcomes[$response->id])) {
                    $state = GradeApplier::applyFailure($response, $outcomes[$response->id]);
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
}
