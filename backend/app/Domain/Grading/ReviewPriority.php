<?php

namespace App\Domain\Grading;

/**
 * Fuzzy system 2 (DESIGN §11.8): how urgently the teacher should look at a
 * response. Inputs are all in 0–1:
 *
 *   D  readers disagree (CNN vs Gemini, mcq ambiguity §11.6, blank vs ink)
 *   L  handwriting is hard to read
 *   B  the understanding value u sits near a level boundary (0.4 / 0.75)
 *
 * Fuzzy sets are low = 1 − x and high = x; AND = min; singleton consequents;
 * weighted-average defuzzification. Rules: P1 D high → 1.0, P2 L high → 0.8,
 * P3 B high → 0.6, P4 D low AND L low AND B low → 0.0. Σw is never 0: either
 * P4 fires or one input is 1 and its rule has weight 1.
 *
 * Bands: p >= 0.5 check, 0.2 <= p < 0.5 look, p < 0.2 confident.
 * suspicious_instruction short-circuits to p = 1 (§11.8 special cases).
 */
final class ReviewPriority
{
    public const BAND_CHECK = 'check';

    public const BAND_LOOK = 'look';

    public const BAND_CONFIDENT = 'confident';

    private const RULES = [
        'P1' => 1.0,
        'P2' => 0.8,
        'P3' => 0.6,
        'P4' => 0.0,
    ];

    /** B from u (§11.8): clamp(1 − min(|u − 0.4|, |u − 0.75|) / 0.1). */
    public static function boundaryCloseness(float $u): float
    {
        $distance = min(abs($u - Understanding::PARTIAL_FROM), abs($u - Understanding::GOOD_FROM));

        return self::clamp(1 - $distance / 0.1);
    }

    /**
     * @return array{p: float, band: string, trace: array<string, mixed>}
     */
    public static function evaluate(float $d, float $l, float $b, bool $suspicious = false): array
    {
        $d = self::clamp($d);
        $l = self::clamp($l);
        $b = self::clamp($b);
        $inputs = ['D' => round($d, 4), 'L' => round($l, 4), 'B' => round($b, 4)];

        if ($suspicious) {
            return [
                'p' => 1.0,
                'band' => self::BAND_CHECK,
                'trace' => ['system' => 'review_priority', 'inputs' => $inputs, 'suspicious_instruction' => true, 'p' => 1.0],
            ];
        }

        $weights = [
            'P1' => $d,
            'P2' => $l,
            'P3' => $b,
            'P4' => min(1 - $d, 1 - $l, 1 - $b),
        ];

        $sumW = array_sum($weights);
        $sumWz = 0.0;
        foreach ($weights as $rule => $w) {
            $sumWz += $w * self::RULES[$rule];
        }
        $p = $sumW > 0 ? $sumWz / $sumW : 1.0;
        $p = round(self::clamp($p), 4);

        return [
            'p' => $p,
            'band' => self::band($p),
            'trace' => [
                'system' => 'review_priority',
                'inputs' => $inputs,
                'rules' => array_map(
                    fn (string $rule) => ['rule' => $rule, 'w' => round($weights[$rule], 4), 'z' => self::RULES[$rule]],
                    array_keys($weights),
                ),
                'p' => $p,
            ],
        ];
    }

    public static function band(float $p): string
    {
        return match (true) {
            $p >= 0.5 => self::BAND_CHECK,
            $p >= 0.2 => self::BAND_LOOK,
            default => self::BAND_CONFIDENT,
        };
    }

    private static function clamp(float $v): float
    {
        return min(1.0, max(0.0, $v));
    }
}
