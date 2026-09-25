<?php

namespace App\Domain\Grading;

/**
 * ai_score = round(score_ratio x max_points) to a step of 0.5 by default
 * (DESIGN §11.7), clamped to [0, max_points]. A full ratio always gives
 * exactly max_points, even when max_points is not a multiple of the step.
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

        $rounded = round($ratio * $maxPoints / $step) * $step;

        return round(max(0.0, min($maxPoints, $rounded)), 2);
    }
}
