<?php

namespace App\Domain\Grading;

/**
 * ai_score = round_half(score_ratio × max_points): the nearest step of 0.5 by
 * default, 1 or 0.25 allowed (DESIGN §11.7), clamped to [0, max_points]. A
 * full ratio always gives exactly max_points, even when max_points is not a
 * multiple of the step.
 *
 * Halves round up with floor(x + 0.5) exactly like ml/fuzzy round_to_step:
 * PHP's round() pre-rounds before PHP 8.4 (the server runs 8.3), so it could
 * disagree with the reference on values like 2.4999999999999996.
 */
final class ScoreRounding
{
    public const DEFAULT_STEP = 0.5;

    public static function score(float $ratio, float $maxPoints, float $step = self::DEFAULT_STEP): float
    {
        $ratio = max(0.0, min(1.0, $ratio));
        if ($ratio >= 1.0) {
            return round($maxPoints, 2);
        }

        $rounded = floor($ratio * $maxPoints / $step + 0.5) * $step;

        return round(max(0.0, min($maxPoints, $rounded)), 2);
    }
}
