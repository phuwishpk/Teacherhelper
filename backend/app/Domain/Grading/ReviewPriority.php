<?php

namespace App\Domain\Grading;

/**
 * Fuzzy system 2 (DESIGN §11.8): how urgently the teacher should look at a
 * response. Rules in rules/review_priority.php run on FuzzyEngine:
 * P1 D high → 1.0, P2 L high → 0.8, P3 B high → 0.6,
 * P4 D low AND L low AND B low → 0.0 (low = 1 − x, high = x).
 *
 * Bands: p >= 0.5 check, 0.2 <= p < 0.5 look, p < 0.2 confident.
 * suspicious_instruction short-circuits to p = 1 (§11.8 special cases).
 */
final class ReviewPriority
{
    public const BAND_CHECK = 'check';

    public const BAND_LOOK = 'look';

    public const BAND_CONFIDENT = 'confident';

    public const BOUNDARY_WIDTH = 0.1;

    /** B from u (§11.8): clamp(1 − min(|u − 0.4|, |u − 0.75|) / 0.1). */
    public static function boundaryCloseness(float $u): float
    {
        $distance = min(abs($u - Understanding::PARTIAL_FROM), abs($u - Understanding::GOOD_FROM));

        return Membership::clamp(1.0 - $distance / self::BOUNDARY_WIDTH);
    }

    public static function evaluate(float $d, float $l, float $b, bool $suspicious = false): PriorityResult
    {
        $inputs = ['D' => Membership::clamp($d), 'L' => Membership::clamp($l), 'B' => Membership::clamp($b)];

        if ($suspicious) {
            return new PriorityResult(1.0, self::BAND_CHECK, PriorityResult::FLAG_SUSPICIOUS, $inputs);
        }

        $result = RuleSets::engine(RuleSets::REVIEW_PRIORITY)->evaluate($inputs);
        if ($result->isDegenerate()) {
            // Cannot happen with the §11.8 table (P1–P3 or P4 always fires), but never lose a response.
            return new PriorityResult(1.0, self::BAND_CHECK, PriorityResult::FLAG_MANUAL, $inputs, $result);
        }

        $p = (float) $result->output('p');

        return new PriorityResult($p, self::band($p), null, $inputs, $result);
    }

    /** A response the teacher must grade by hand: top of the queue (§11.8). */
    public static function manual(): PriorityResult
    {
        return new PriorityResult(1.0, self::BAND_CHECK, PriorityResult::FLAG_MANUAL, ['D' => 0.0, 'L' => 0.0, 'B' => 0.0]);
    }

    public static function band(float $p): string
    {
        return match (true) {
            $p >= 0.5 => self::BAND_CHECK,
            $p >= 0.2 => self::BAND_LOOK,
            default => self::BAND_CONFIDENT,
        };
    }
}
