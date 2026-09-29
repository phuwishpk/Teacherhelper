<?php

namespace App\Domain\Grading;

use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\ExplanationRequests;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Review\ReviewFlags;
use App\Models\Response;
use App\Models\ScoreEvent;

/**
 * What both grading paths (ScanGrader for crops, WholePageGrader for whole
 * pages) do after the extraction:
 *
 * - explain(): template praise for full marks, the blank template for blank
 *   answers, Gemini `explanation` (text only, §10.5) for anything else
 *   below full marks, nothing for suspicious ones (the teacher looks first);
 *   an assignment set to "เฉพาะคะแนน" (score_only, §21.7) never calls
 *   Gemini: the score-only template instead; an identical wrong answer to
 *   the same question reuses the stored text (ExplanationCache, §21.7
 *   item 6, explanation_source = reused);
 * - applyGrade() / applyFailure() / markManual(): the response columns,
 *   the `ai_scored` score event and the sticky review flags. The teacher's
 *   own explanation is never overwritten; explanation_source says where the
 *   text came from (ai | template | reused, §19.8).
 *
 * The write methods run inside the caller's transaction.
 */
final class GradeApplier
{
    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly ExplanationRequests $explanations,
        private readonly ExplanationCache $cache,
    ) {}

    /**
     * @param  array<int, GradeOutcome>  $graded  by response id
     * @param  array<int, Response>  $responses  by response id, with question.rubricCriteria
     * @param  array<int, array<string, mixed>>  $extractions  by response id
     * @return array{0: array<int, array{text: string, source: string}>, 1: array<int, string>} explanations, failed explanation statuses
     */
    public function explain(array $graded, array $responses, array $extractions, GeminiKey $key, string $gradeLabel, bool $scoreOnly = false): array
    {
        $explanations = [];
        $wanted = [];
        foreach ($graded as $id => $grade) {
            $response = $responses[$id];
            $max = (float) $response->question->max_points;
            if (! $grade->isScored()) {
                continue;
            }
            if ($grade->score !== null && $grade->score >= $max) {
                $explanations[$id] = ['text' => FeedbackTemplates::praise($id), 'source' => Response::EXPLANATION_TEMPLATE];
            } elseif ($grade->blank) {
                $explanations[$id] = ['text' => FeedbackTemplates::BLANK, 'source' => Response::EXPLANATION_TEMPLATE];
            } elseif ($scoreOnly) {
                if (! $grade->suspicious) {
                    $explanations[$id] = ['text' => FeedbackTemplates::SCORE_ONLY, 'source' => Response::EXPLANATION_TEMPLATE];
                }
            } elseif (! $grade->suspicious && $response->question->type !== 'mcq') {
                $wanted[$id] = ExplanationCache::hash($response->question, $extractions[$id]);
            }
        }

        // §21.7 item 6: an identical wrong answer to the same question reuses
        // the stored text (the teacher's edit first); within this batch only
        // the first of a group asks Gemini, the others take its answer.
        $pairs = [];
        foreach ($wanted as $id => $hash) {
            if ($hash !== null) {
                $pairs[] = [(int) $responses[$id]->question_id, $hash];
            }
        }
        $stored = $this->cache->find($pairs);
        $calls = [];
        $leaders = [];
        $followers = [];
        foreach ($wanted as $id => $hash) {
            $response = $responses[$id];
            $group = $hash === null ? null : $response->question_id.':'.$hash;
            if ($group !== null && isset($stored[$group])) {
                $explanations[$id] = ['text' => $stored[$group]['explanation'], 'source' => Response::EXPLANATION_REUSED];

                continue;
            }
            if ($group !== null && isset($leaders[$group])) {
                $followers[$id] = $leaders[$group];

                continue;
            }
            if ($group !== null) {
                $leaders[$group] = $id;
            }
            $calls[$id] = $this->explanations->forResponse(
                $response,
                $response->question,
                $response->question->rubricCriteria->all(),
                $gradeLabel,
                $extractions[$id],
            );
        }

        $errors = [];
        if ($calls !== []) {
            $explained = $this->gateway->run($calls, $key);
            foreach (array_keys($calls) as $id) {
                $outcome = $explained[$id] ?? null;
                if ($outcome?->isOk()) {
                    $text = ExplanationRequests::text((array) $outcome->data);
                    $explanations[$id] = ['text' => $text, 'source' => Response::EXPLANATION_AI];
                    if ($wanted[$id] !== null) {
                        $this->cache->remember((int) $responses[$id]->question_id, $wanted[$id], $text, ExplanationCache::SOURCE_AI, $id);
                    }
                } else {
                    $errors[$id] = $outcome->status ?? CallOutcome::ERROR;
                }
            }
        }
        foreach ($followers as $id => $leader) {
            if (isset($explanations[$leader])) {
                $explanations[$id] = ['text' => $explanations[$leader]['text'], 'source' => Response::EXPLANATION_REUSED];
            } else {
                $errors[$id] = $errors[$leader] ?? CallOutcome::ERROR;
            }
        }

        return [$explanations, $errors];
    }

    /**
     * @param  array<string, mixed>  $extraction
     * @param  array{text: string, source: string}|null  $explanation
     * @param  array<string, mixed>  $traceExtra  added to the trace (e.g. the whole-page merge)
     */
    public static function applyGrade(Response $response, GradeOutcome $grade, array $extraction, ?array $explanation, ?string $explanationError, array $traceExtra = []): string
    {
        $trace = $grade->trace + $traceExtra;
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
            'auto_rule' => $grade->autoRule,
        ]);
        if (! $response->explanation_edited) {
            $response->explanation = $explanation['text'] ?? null;
            $response->explanation_source = $explanation['source'] ?? null;
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

    /** One more failed attempt: `failed` while attempts are left, then `manual`. */
    public static function applyFailure(Response $response, CallOutcome $outcome): string
    {
        $response->attempts++;
        if ($outcome->status === CallOutcome::KEY_INVALID) {
            self::markManual($response, ScanGrader::REASON_KEY_INVALID, $outcome->status);

            return Response::STATE_MANUAL;
        }
        if ($response->attempts >= ScanGrader::MAX_ATTEMPTS) {
            $reason = $outcome->status === CallOutcome::INVALID_OUTPUT ? ScanGrader::REASON_INVALID_OUTPUT : ScanGrader::REASON_AI_ERROR;
            self::markManual($response, $reason, $outcome->status);

            return Response::STATE_MANUAL;
        }

        $response->grading_state = Response::STATE_FAILED;
        $response->fuzzy_trace = ReviewFlags::carry($response->fuzzy_trace, ['last_error' => $outcome->status]);
        $response->save();

        return Response::STATE_FAILED;
    }

    /**
     * @param  array<string, mixed>  $traceExtra
     */
    public static function markManual(Response $response, string $reason, ?string $lastError = null, array $traceExtra = []): void
    {
        $priority = ReviewPriority::manual();
        $response->forceFill([
            'grading_state' => Response::STATE_MANUAL,
            'fuzzy_trace' => ReviewFlags::carry($response->fuzzy_trace, array_filter(['manual_reason' => $reason, 'last_error' => $lastError]) + $traceExtra),
            'review_priority' => $priority->storedP(),
            'priority_band' => $priority->band,
            'auto_rule' => null,
        ])->save();
    }
}
