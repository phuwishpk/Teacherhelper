<?php

namespace App\Domain\Review;

use App\Models\Question;

/**
 * What a teacher may enter as a score (DESIGN §11.7, §13): 0 to the
 * question's max_points in steps of 0.25 (the finest step the design
 * allows), or exactly max_points. "Differs from the AI" means more than a
 * rounding hair apart.
 */
final class ScoreRules
{
    public const STEP = 0.25;

    private const EPS = 0.001;

    /** The error types of the extract schemas (DESIGN §10.3), shared by ai_/final_error_types. */
    public const ERROR_TYPES = [
        'concept', 'procedure', 'calculation', 'careless', 'incomplete',
        'misread_question', 'spelling_grammar', 'no_answer', 'other',
    ];

    /** Thai validation message, or null when the score is allowed. */
    public static function invalid(float $score, Question $question): ?string
    {
        $max = (float) $question->max_points;
        if ($score < 0 || $score > $max + self::EPS) {
            return 'คะแนนต้องอยู่ระหว่าง 0 ถึง '.self::format($max);
        }
        $steps = $score / self::STEP;
        if (abs($steps - round($steps)) > self::EPS && abs($score - $max) > self::EPS) {
            return 'คะแนนต้องเป็นทีละ 0.25';
        }

        return null;
    }

    public static function differs(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return $a !== $b;
        }

        return abs($a - $b) > self::EPS;
    }

    public static function format(float $value): string
    {
        $s = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }
}
