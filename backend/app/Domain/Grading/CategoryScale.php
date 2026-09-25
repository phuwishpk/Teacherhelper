<?php

namespace App\Domain\Grading;

use InvalidArgumentException;

/**
 * Gemini category -> number (DESIGN §11.2; twin of ml/fuzzy/scale.py).
 * Gemini always answers with categories; this is the only place they become
 * fuzzy inputs.
 */
final class CategoryScale
{
    public const MATCH = ['exact', 'equivalent', 'partial', 'different', 'missing'];

    public const CRITERIA_LEVELS = ['met', 'partially_met', 'not_met'];

    public const LEGIBILITY = ['clear', 'readable', 'hard'];

    /** `partial` is worth 0.5, except 0.6 for `short`. */
    public const PARTIAL_DEFAULT = 0.5;

    public const PARTIAL_SHORT = 0.6;

    /** final_answer_match / key_match -> 0..1 */
    public static function matchValue(string $category, string $questionType = 'show_work'): float
    {
        return match ($category) {
            'exact', 'equivalent' => 1.0,
            'partial' => $questionType === 'short' ? self::PARTIAL_SHORT : self::PARTIAL_DEFAULT,
            'different', 'missing' => 0.0,
            default => throw new InvalidArgumentException("unknown match category {$category}"),
        };
    }

    /** criteria[].level -> 0..1 */
    public static function criteriaLevelValue(string $level): float
    {
        return match ($level) {
            'met' => 1.0,
            'partially_met' => 0.5,
            'not_met' => 0.0,
            default => throw new InvalidArgumentException("unknown criteria level {$level}"),
        };
    }

    /** legibility -> illegibility 0..1 (clear 0, readable 0.4, hard 1). */
    public static function legibilityValue(string $legibility): float
    {
        return match ($legibility) {
            'clear' => 0.0,
            'readable' => 0.4,
            'hard' => 1.0,
            default => throw new InvalidArgumentException("unknown legibility {$legibility}"),
        };
    }
}
