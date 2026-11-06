<?php

namespace App\Domain\Grading;

use App\Models\Question;
use App\Models\Response;

/**
 * Answers the crop path decides by code, before any Gemini call (DESIGN
 * §21.3). Such an answer has no ai_calls row; responses.auto_rule says
 * which rule decided it, and the savings report counts those rows (§21.8).
 *
 * - blank_ink: ink_ratio below eduvision.grading.blank_ink_max (0.005,
 *   stricter than the 0.02 of §11.8): 0 points, u = 0, error_types
 *   [no_answer], band `look` (never `confident`) so the teacher still sees
 *   the crop. A show_work answer with a final-answer box is never skipped:
 *   the phone measures the ink of the working area only, and the final box
 *   may hold an answer.
 * - cnn_match (behind eduvision.grading.cnn_skip_enabled, off by default):
 *   a `short` answer with a numeric box whose on-device digit reading has
 *   cnn_confidence >= cnn_skip_min_confidence and, after the §11.4
 *   normalisation, equals one accepted answer exactly: full marks, u = 1,
 *   band `confident` (approvable in bulk), except a sample of
 *   cnn_skip_sample_rate that goes to `look`. The sample is drawn from the
 *   response id (a stable hash), so a regrade never moves an answer
 *   between the two.
 *
 * The extraction stored with these answers has the shape of the question
 * type's `extract` output (plus auto_rule), so everything that reads
 * extraction (review screen, regenerate explanation) works unchanged.
 */
final class AutoRules
{
    /** review_priority stored for an answer sent to the `look` band by a rule. */
    public const LOOK_P = 0.3;

    public static function decide(Response $response, Question $question): ?string
    {
        if (self::blankInk($response, $question)) {
            return Response::AUTO_BLANK_INK;
        }
        if (self::cnnMatch($response, $question)) {
            return Response::AUTO_CNN_MATCH;
        }

        return null;
    }

    public static function blankInk(Response $response, Question $question): bool
    {
        if ($response->ink_ratio === null || $question->type === Question::TYPE_MCQ) {
            return false;
        }
        if ($question->type === Question::TYPE_SHOW_WORK && $response->final_crop_path !== null) {
            return false;
        }

        return $response->ink_ratio < (float) config('eduvision.grading.blank_ink_max', 0.005);
    }

    public static function cnnMatch(Response $response, Question $question): bool
    {
        if (! (bool) config('eduvision.grading.cnn_skip_enabled', false)
            || $question->type !== Question::TYPE_SHORT
            || ! $question->is_numeric
            || $response->cnn_text === null
            || $response->cnn_confidence === null
            || $response->cnn_confidence < (float) config('eduvision.grading.cnn_skip_min_confidence', 0.97)) {
            return false;
        }
        $read = AnswerMatcher::normalize($response->cnn_text);
        if ($read === '') {
            return false;
        }
        foreach ((array) (($question->answer_key ?? [])['accepted'] ?? []) as $accepted) {
            if (AnswerMatcher::normalize((string) $accepted) === $read) {
                return true;
            }
        }

        return false;
    }

    /** Whether this cnn_match answer is one of the sample the teacher looks at. */
    public static function sampled(int $responseId, float $rate): bool
    {
        if ($rate <= 0.0) {
            return false;
        }
        if ($rate >= 1.0) {
            return true;
        }

        return crc32('cnn-skip-sample:'.$responseId) % 10000 < (int) round($rate * 10000);
    }

    /**
     * The grade of an answer a rule decided, shaped like ResponseGrader's.
     *
     * @return array{0: GradeOutcome, 1: array<string, mixed>} the grade and the stored extraction
     */
    public static function grade(string $rule, Response $response, Question $question, int $criteriaCount, string $strictness): array
    {
        $maxPoints = (float) $question->max_points;
        $blank = $rule === Response::AUTO_BLANK_INK;
        $sampled = ! $blank && self::sampled($response->id, (float) config('eduvision.grading.cnn_skip_sample_rate', 0.10));
        $priority = $blank || $sampled
            ? new PriorityResult(self::LOOK_P, ReviewPriority::BAND_LOOK, null, ['D' => 0.0, 'L' => 0.0, 'B' => 0.0])
            : new PriorityResult(0.0, ReviewPriority::BAND_CONFIDENT, null, ['D' => 0.0, 'L' => 0.0, 'B' => 0.0]);
        $ratio = $blank ? 0.0 : 1.0;
        $score = ScoreRounding::score($ratio, $maxPoints);
        $errorTypes = $blank ? ['no_answer'] : [];

        $signals = $blank
            ? ['ink_ratio' => $response->ink_ratio, 'blank_ink_max' => (float) config('eduvision.grading.blank_ink_max', 0.005)]
            : [
                'cnn_text' => $response->cnn_text,
                'cnn_confidence' => $response->cnn_confidence,
                'min_confidence' => (float) config('eduvision.grading.cnn_skip_min_confidence', 0.97),
                'sampled' => $sampled,
            ];

        $grade = new GradeOutcome(
            state: GradeResult::SCORED,
            scoreRatio: $ratio,
            score: $score,
            understanding: $blank ? Understanding::NOT_YET : Understanding::GOOD,
            errorTypes: $errorTypes,
            reviewPriority: $priority->storedP(),
            priorityBand: $priority->band,
            trace: [
                'version' => ResponseGrader::TRACE_VERSION,
                'system' => $question->type,
                'strictness' => $strictness,
                'max_points' => $maxPoints,
                'blank' => $blank,
                'suspicious_instruction' => false,
                'auto_rule' => $rule,
                'inputs' => [],
                'signals' => $signals,
                'score' => null,
                'score_ratio' => $ratio,
                'u' => $ratio,
                'understanding' => $blank ? Understanding::NOT_YET : Understanding::GOOD,
                'ai_score' => $score,
                'priority' => $priority->toArray(),
            ],
            blank: $blank,
            autoRule: $rule,
        );

        return [$grade, self::extraction($rule, $response, $question, $criteriaCount)];
    }

    /**
     * The templates GradeApplier::explain() would pick, with no Gemini call:
     * the blank nudge for blank_ink, praise for the full marks of cnn_match.
     *
     * @return array{text: string, source: string}
     */
    public static function explanation(int $responseId, GradeOutcome $grade): array
    {
        return [
            'text' => $grade->blank ? FeedbackTemplates::BLANK : FeedbackTemplates::praise($responseId),
            'source' => Response::EXPLANATION_TEMPLATE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extraction(string $rule, Response $response, Question $question, int $criteriaCount): array
    {
        $common = ['blank' => $rule === Response::AUTO_BLANK_INK, 'suspicious_instruction' => false, 'legibility' => 'clear'];
        if ($rule === Response::AUTO_CNN_MATCH) {
            return $common + [
                'answer_text' => (string) $response->cnn_text,
                'key_match' => 'exact',
                'error_types' => [],
                'summary_th' => 'อ่านด้วย CNN',
                'auto_rule' => $rule,
            ];
        }

        $blank = ['error_types' => ['no_answer'], 'summary_th' => 'ไม่ได้ตอบ', 'auto_rule' => $rule];

        return $common + match ($question->type) {
            Question::TYPE_SHORT => ['answer_text' => '', 'key_match' => 'missing'],
            Question::TYPE_SHOW_WORK => ['steps' => [], 'final_answer_text' => '', 'final_answer_match' => 'missing'],
            default => [
                'transcription' => '',
                'criteria' => array_map(fn (int $id) => ['criterion_id' => $id, 'level' => 'not_met'], $criteriaCount > 0 ? range(1, $criteriaCount) : []),
            ],
        } + $blank;
    }
}
