<?php

namespace App\Domain\Grading;

use InvalidArgumentException;

/**
 * Fuzzy system 1 (DESIGN §11.3–§11.5; twin of ml/fuzzy/grading.py): turns the
 * numbers derived from Gemini's categories into score_ratio and u, then the
 * understanding level (§11.7). A blank answer skips fuzzy (§11.1).
 */
final class FuzzyGrader
{
    /** F = final-answer correctness, S = share of valid steps (§11.3). */
    public static function showWork(float $f, float $s, string $strictness = 'normal'): GradeResult
    {
        return self::run(RuleSets::SHOW_WORK, ['F' => $f, 'S' => $s], $strictness);
    }

    /** M = match with the key (§11.4, AnswerMatcher). */
    public static function short(float $m, string $strictness = 'normal'): GradeResult
    {
        return self::run(RuleSets::SHORT, ['M' => $m], $strictness);
    }

    /** K = core criterion level, R = points-weighted mean of the rest (§11.5). */
    public static function open(float $k, float $r, string $strictness = 'normal'): GradeResult
    {
        return self::run(RuleSets::OPEN, ['K' => $k, 'R' => $r], $strictness);
    }

    /** Gemini says blank and nothing contradicts it: score 0, u 0, no fuzzy (§11.1). */
    public static function blank(string $system, string $strictness = 'normal'): GradeResult
    {
        return new GradeResult($system, $strictness, GradeResult::SCORED, 0.0, 0.0, Understanding::NOT_YET, [], null);
    }

    /**
     * S of §11.3: valid steps / all steps, 0 without steps.
     *
     * @param  list<array{valid: bool}|bool>  $steps
     */
    public static function stepRatio(array $steps): float
    {
        if ($steps === []) {
            return 0.0;
        }
        $valid = 0;
        foreach ($steps as $step) {
            $valid += (is_array($step) ? (bool) $step['valid'] : (bool) $step) ? 1 : 0;
        }

        return $valid / count($steps);
    }

    /**
     * (K, R) of §11.5 from [{level, points, is_core}]. Without a core criterion
     * both are the points-weighted mean of all criteria.
     *
     * @param  list<array{level: string, points: float|int, is_core: bool}>  $criteria
     * @return array{0: float, 1: float}
     */
    public static function openInputs(array $criteria): array
    {
        if ($criteria === []) {
            throw new InvalidArgumentException('an open question needs at least one rubric criterion');
        }
        $core = array_values(array_filter($criteria, fn (array $c) => (bool) ($c['is_core'] ?? false)));
        if (count($core) > 1) {
            throw new InvalidArgumentException('an open question has at most one core criterion');
        }
        if ($core === []) {
            $mean = self::weightedMean($criteria);

            return [$mean, $mean];
        }
        $rest = array_values(array_filter($criteria, fn (array $c) => ! (bool) ($c['is_core'] ?? false)));
        $k = CategoryScale::criteriaLevelValue($core[0]['level']);

        return [$k, $rest === [] ? $k : self::weightedMean($rest)];
    }

    /**
     * @param  list<array{level: string, points: float|int}>  $criteria
     */
    private static function weightedMean(array $criteria): float
    {
        $total = 0.0;
        foreach ($criteria as $c) {
            $total += (float) $c['points'];
        }
        if ($total <= 0) {
            throw new InvalidArgumentException('rubric points must sum to a positive number');
        }
        $sum = 0.0;
        foreach ($criteria as $c) {
            $sum += (float) $c['points'] * CategoryScale::criteriaLevelValue($c['level']);
        }

        return $sum / $total;
    }

    /**
     * @param  array<string, float>  $inputs
     */
    private static function run(string $system, array $inputs, string $strictness): GradeResult
    {
        $result = RuleSets::engine($system, $strictness)->evaluate($inputs);
        if ($result->isDegenerate()) {
            return new GradeResult($system, $strictness, GradeResult::MANUAL, null, null, null, $result->inputs, $result);
        }
        $u = (float) $result->output('u');

        return new GradeResult(
            $system,
            $strictness,
            GradeResult::SCORED,
            (float) $result->output('score_ratio'),
            $u,
            Understanding::fromU($u),
            $result->inputs,
            $result,
        );
    }
}
