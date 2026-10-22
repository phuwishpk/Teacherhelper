<?php

namespace App\Domain\Grading;

/**
 * Inputs D and L of fuzzy system 2 (DESIGN §11.8; twin of the helpers in
 * ml/fuzzy/priority.py). B is ReviewPriority::boundaryCloseness().
 *
 *   D = max(CNN vs Gemini on a numeric box: same 0, differ 1, CNN abstained 0.5;
 *           mcq ambiguity (§11.6); Gemini says blank but ink_ratio > 0.02: 1)
 *   L = max(legibility value (§11.2), min(1, 5 × share of [?] characters))
 */
final class PrioritySignals
{
    public const CNN_ABSTAINED = 0.5;

    public const BLANK_INK_RATIO_MAX = 0.02;

    public const UNKNOWN_CHAR_SCALE = 5.0;

    public const UNKNOWN_MARK = '[?]';

    public static function readerDisagreement(?string $cnnText, ?string $geminiText, bool $numeric): float
    {
        if (! $numeric) {
            return 0.0;
        }
        if ($cnnText === null) {
            return self::CNN_ABSTAINED;
        }
        $gemini = $geminiText ?? '';
        $a = AnswerMatcher::parseNumber($cnnText);
        $b = AnswerMatcher::parseNumber($gemini);
        if ($a !== null && $b !== null) {
            return $a == $b ? 0.0 : 1.0;
        }

        return AnswerMatcher::normalize($cnnText) === AnswerMatcher::normalize($gemini) ? 0.0 : 1.0;
    }

    public static function blankDisagreement(bool $blank, ?float $inkRatio): float
    {
        return $blank && $inkRatio !== null && $inkRatio > self::BLANK_INK_RATIO_MAX ? 1.0 : 0.0;
    }

    public static function disagreement(
        bool $numeric = false,
        ?string $cnnText = null,
        ?string $geminiText = null,
        float $mcqAmbiguity = 0.0,
        bool $blank = false,
        ?float $inkRatio = null,
    ): float {
        return max(
            self::readerDisagreement($cnnText, $geminiText, $numeric),
            Membership::clamp($mcqAmbiguity),
            self::blankDisagreement($blank, $inkRatio),
        );
    }

    /** Share of [?] marks among the transcribed characters, whitespace ignored. */
    public static function unknownRatio(string $text): float
    {
        $unknown = substr_count($text, self::UNKNOWN_MARK);
        $rest = (string) preg_replace('/[\s\p{Z}]+/u', '', str_replace(self::UNKNOWN_MARK, '', $text));
        $total = $unknown + mb_strlen($rest, 'UTF-8');

        return $total === 0 ? 0.0 : $unknown / $total;
    }

    public static function illegibility(string $legibility, string $transcription = ''): float
    {
        return max(
            CategoryScale::legibilityValue($legibility),
            min(1.0, self::UNKNOWN_CHAR_SCALE * self::unknownRatio($transcription)),
        );
    }
}
