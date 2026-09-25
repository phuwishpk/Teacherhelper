<?php

namespace App\Domain\Grading;

/**
 * Multiple choice is graded on upload from the bubble fill the phone measured
 * (DESIGN §11.6), without fuzzy system 1 or Gemini:
 *
 * - a bubble counts as filled when fill >= 0.45;
 * - exactly one filled bubble that matches the key scores 1, anything else
 *   (wrong, none, several) scores 0; u follows the score (1 or 0);
 * - ambiguity D for fuzzy system 2: several filled bubbles D = 1, any bubble
 *   between 0.2 and 0.45 D = 0.6, else 0. L = 0 (no handwriting to read).
 */
final class McqGrader
{
    public const FILLED_FROM = 0.45;

    public const AMBIGUOUS_FROM = 0.2;

    /**
     * @param  array<string, float|int>  $fill  option => fill ratio 0–1
     */
    public static function grade(array $fill, string $correct, float $maxPoints): McqGrade
    {
        $filled = [];
        $ambiguous = [];
        foreach ($fill as $option => $value) {
            $value = (float) $value;
            if ($value >= self::FILLED_FROM) {
                $filled[] = (string) $option;
            } elseif ($value >= self::AMBIGUOUS_FROM) {
                $ambiguous[] = (string) $option;
            }
        }

        $ratio = count($filled) === 1 && $filled[0] === $correct ? 1.0 : 0.0;
        $d = match (true) {
            count($filled) > 1 => 1.0,
            $ambiguous !== [] => 0.6,
            default => 0.0,
        };
        $u = $ratio;
        $priority = ReviewPriority::evaluate($d, 0.0, ReviewPriority::boundaryCloseness($u));

        return new McqGrade(
            scoreRatio: $ratio,
            score: ScoreRounding::score($ratio, $maxPoints),
            understanding: Understanding::fromU($u),
            errorTypes: $filled === [] ? ['no_answer'] : [],
            reviewPriority: $priority->storedP(),
            priorityBand: $priority->band,
            trace: [
                'system' => 'mcq',
                'fill' => array_map(fn ($v) => round((float) $v, 4), $fill),
                'filled_from' => self::FILLED_FROM,
                'filled' => $filled,
                'ambiguous' => $ambiguous,
                'correct' => $correct,
                'score_ratio' => $ratio,
                'u' => $u,
                'review_priority' => $priority->toArray(),
            ],
        );
    }
}
