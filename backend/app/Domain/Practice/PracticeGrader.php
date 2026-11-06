<?php

namespace App\Domain\Practice;

use App\Domain\Grading\AnswerMatcher;
use App\Models\PracticeItem;

/**
 * Deterministic grading of a typed practice answer (DESIGN §14.1): no
 * Gemini, the same AnswerMatcher as the `short` fuzzy system (§11.4).
 *
 * - numeric / short: AnswerMatcher::shortMatch with the item's answer_key
 *   (a numeric key applies when the answer parses as a number, otherwise the
 *   flexible text rule against the accepted answers);
 * - mcq: 1 when the answer is the correct option's key (A, B, …) or its
 *   text, else 0.
 */
final class PracticeGrader
{
    /** score_ratio in 0..1 */
    public static function grade(PracticeItem $item, string $answer): float
    {
        $key = $item->answer_key ?? [];
        if ($item->answer_type === PracticeItem::TYPE_MCQ) {
            return self::mcq($item, $answer);
        }
        if (! is_array($key['accepted'] ?? null) || $key['accepted'] === []) {
            return 0.0;
        }

        return round(max(0.0, min(1.0, AnswerMatcher::shortMatch($answer, $key, 'flexible'))), 3);
    }

    private static function mcq(PracticeItem $item, string $answer): float
    {
        $correct = mb_strtoupper(trim((string) ($item->answer_key['correct'] ?? '')), 'UTF-8');
        $normalized = AnswerMatcher::normalize($answer);
        if ($correct === '' || $normalized === '') {
            return 0.0;
        }
        if (mb_strtoupper($normalized, 'UTF-8') === $correct) {
            return 1.0;
        }
        foreach ((array) $item->options as $option) {
            $optionKey = mb_strtoupper(trim((string) ($option['key'] ?? '')), 'UTF-8');
            if ($optionKey === $correct && AnswerMatcher::normalize((string) ($option['text'] ?? '')) === $normalized) {
                return 1.0;
            }
        }

        return 0.0;
    }
}
