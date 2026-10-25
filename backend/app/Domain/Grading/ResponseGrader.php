<?php

namespace App\Domain\Grading;

use App\Models\Question;
use App\Models\RubricCriterion;
use InvalidArgumentException;

/**
 * Turns one validated Gemini extraction into a grade (DESIGN §11):
 *
 *   show_work  F = final_answer_match lifted by the numeric key, S = valid steps / steps
 *   short      M = AnswerMatcher (flexible / exact / numeric)
 *   open       K = core criterion level, R = weighted mean of the rest
 *
 * then fuzzy system 1 (score_ratio, u -> ai_score, ai_understanding) and
 * system 2 (review priority from D, L, B). A blank answer skips fuzzy
 * (score 0, u 0, §11.1); a blank contradicted by ink still gets D = 1.
 * suspicious_instruction forces priority 1.0 (§10.7). Σw = 0 -> manual.
 *
 * $disagreement replaces D when the caller has no CNN or ink reading: the
 * whole-page path (§19.4) passes 1 when two pages disagree, else 0.
 */
final class ResponseGrader
{
    public const TRACE_VERSION = 1;

    /**
     * @param  array<string, mixed>  $extraction  validated output of `extract`
     * @param  list<RubricCriterion>  $criteria  open questions, in position order (criterion_id = index + 1)
     */
    public static function grade(
        Question $question,
        array $criteria,
        string $strictness,
        array $extraction,
        ?string $cnnText = null,
        ?float $inkRatio = null,
        ?float $disagreement = null,
    ): GradeOutcome {
        $type = $question->type;
        $key = $question->answer_key ?? [];
        $blank = (bool) $extraction['blank'];
        $suspicious = (bool) $extraction['suspicious_instruction'];
        $maxPoints = (float) $question->max_points;
        $signals = [];

        try {
            [$result, $geminiText, $transcription] = match ($type) {
                Question::TYPE_SHOW_WORK => self::showWork($question, $key, $extraction, $strictness, $blank, $signals),
                Question::TYPE_SHORT => self::short($question, $key, $extraction, $strictness, $blank, $signals),
                Question::TYPE_OPEN => self::open($criteria, $extraction, $strictness, $blank, $signals),
                default => throw new InvalidArgumentException("{$type} is not graded with fuzzy"),
            };
        } catch (InvalidArgumentException $e) {
            // A key or rubric the fuzzy inputs cannot be built from (no accepted answers, no criteria).
            return self::manual($type, $strictness, $extraction, 'answer_key_missing', $e->getMessage());
        }

        $trace = [
            'version' => self::TRACE_VERSION,
            'system' => $type,
            'strictness' => $strictness,
            'max_points' => $maxPoints,
            'blank' => $blank,
            'suspicious_instruction' => $suspicious,
            'inputs' => $result->inputs,
            'signals' => $signals,
            'score' => $result->trace?->toArray(),
        ];

        if (! $result->isScored()) {
            $priority = ReviewPriority::manual();

            return new GradeOutcome(
                state: GradeResult::MANUAL,
                scoreRatio: null,
                score: null,
                understanding: null,
                errorTypes: self::errorTypes($extraction),
                reviewPriority: $priority->storedP(),
                priorityBand: $priority->band,
                trace: $trace + ['manual_reason' => 'fuzzy_degenerate', 'priority' => $priority->toArray()],
                blank: $blank,
                suspicious: $suspicious,
                manualReason: 'fuzzy_degenerate',
            );
        }

        $u = (float) $result->u;
        $d = $disagreement ?? PrioritySignals::disagreement(
            numeric: $question->is_numeric && $type !== Question::TYPE_OPEN,
            cnnText: $cnnText,
            geminiText: $geminiText,
            blank: $blank,
            inkRatio: $inkRatio,
        );
        $l = PrioritySignals::illegibility((string) $extraction['legibility'], $transcription);
        $b = ReviewPriority::boundaryCloseness($u);
        $priority = ReviewPriority::evaluate($d, $l, $b, $suspicious);
        $score = $result->score($maxPoints);

        return new GradeOutcome(
            state: GradeResult::SCORED,
            scoreRatio: $result->scoreRatio,
            score: $score,
            understanding: $result->understanding,
            errorTypes: self::errorTypes($extraction),
            reviewPriority: $priority->storedP(),
            priorityBand: $priority->band,
            trace: $trace + [
                'score_ratio' => $result->scoreRatio,
                'u' => $u,
                'understanding' => $result->understanding,
                'ai_score' => $score,
                'priority' => $priority->toArray() + ['signals' => [
                    'numeric_box' => $question->is_numeric && $type !== Question::TYPE_OPEN,
                    'cnn_text' => $cnnText,
                    'gemini_text' => $geminiText,
                    'ink_ratio' => $inkRatio,
                    'legibility' => $extraction['legibility'],
                ]],
            ],
            blank: $blank,
            suspicious: $suspicious,
        );
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $x
     * @param  array<string, mixed>  $signals
     * @return array{0: GradeResult, 1: string, 2: string}
     */
    private static function showWork(Question $q, array $key, array $x, string $strictness, bool $blank, array &$signals): array
    {
        $final = (string) $x['final_answer_text'];
        $transcription = trim(implode("\n", array_map(fn (array $s) => (string) $s['text'], $x['steps']))."\n".$final);
        if ($blank) {
            return [FuzzyGrader::blank(RuleSets::SHOW_WORK, $strictness), $final, $transcription];
        }

        $f = AnswerMatcher::finalAnswerValue((string) $x['final_answer_match'], $final, isset($key['final']) ? (array) $key['final'] : null);
        $s = FuzzyGrader::stepRatio($x['steps']);
        $signals = [
            'final_answer_match' => $x['final_answer_match'],
            'valid_steps' => count(array_filter($x['steps'], fn (array $st) => (bool) $st['valid'])),
            'steps' => count($x['steps']),
        ];

        return [FuzzyGrader::showWork($f, $s, $strictness), $final, $transcription];
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $x
     * @param  array<string, mixed>  $signals
     * @return array{0: GradeResult, 1: string, 2: string}
     */
    private static function short(Question $q, array $key, array $x, string $strictness, bool $blank, array &$signals): array
    {
        $answer = (string) $x['answer_text'];
        if ($blank) {
            return [FuzzyGrader::blank(RuleSets::SHORT, $strictness), $answer, $answer];
        }

        $m = AnswerMatcher::shortMatch($answer, $key, $q->match_mode, (string) $x['key_match']);
        $signals = [
            'key_match' => $x['key_match'],
            'match_mode' => $q->match_mode,
            'numeric_key' => is_array($key['numeric'] ?? null),
        ];

        return [FuzzyGrader::short($m, $strictness), $answer, $answer];
    }

    /**
     * @param  list<RubricCriterion>  $criteria
     * @param  array<string, mixed>  $x
     * @param  array<string, mixed>  $signals
     * @return array{0: GradeResult, 1: string, 2: string}
     */
    private static function open(array $criteria, array $x, string $strictness, bool $blank, array &$signals): array
    {
        $transcription = (string) $x['transcription'];
        if ($blank) {
            return [FuzzyGrader::blank(RuleSets::OPEN, $strictness), $transcription, $transcription];
        }

        $levels = [];
        foreach ($x['criteria'] as $c) {
            $levels[(int) $c['criterion_id']] = (string) $c['level'];
        }
        $rows = [];
        foreach ($criteria as $i => $criterion) {
            if (! isset($levels[$i + 1])) {
                throw new InvalidArgumentException('criterion '.($i + 1).' has no level');
            }
            $rows[] = ['level' => $levels[$i + 1], 'points' => (float) $criterion->points, 'is_core' => (bool) $criterion->is_core];
        }
        [$k, $r] = FuzzyGrader::openInputs($rows);
        $signals = ['criteria' => $rows];

        return [FuzzyGrader::open($k, $r, $strictness), $transcription, $transcription];
    }

    /**
     * @param  array<string, mixed>  $extraction
     * @return list<string>
     */
    private static function errorTypes(array $extraction): array
    {
        return array_values(array_unique(array_map('strval', (array) ($extraction['error_types'] ?? []))));
    }

    /**
     * @param  array<string, mixed>  $extraction
     */
    private static function manual(string $type, string $strictness, array $extraction, string $reason, string $detail): GradeOutcome
    {
        $priority = ReviewPriority::manual();

        return new GradeOutcome(
            state: GradeResult::MANUAL,
            scoreRatio: null,
            score: null,
            understanding: null,
            errorTypes: self::errorTypes($extraction),
            reviewPriority: $priority->storedP(),
            priorityBand: $priority->band,
            trace: [
                'version' => self::TRACE_VERSION,
                'system' => $type,
                'strictness' => $strictness,
                'manual_reason' => $reason,
                'detail' => $detail,
                'priority' => $priority->toArray(),
            ],
            blank: (bool) ($extraction['blank'] ?? false),
            suspicious: (bool) ($extraction['suspicious_instruction'] ?? false),
            manualReason: $reason,
        );
    }
}
