<?php

namespace App\Domain\Grading;

use InvalidArgumentException;

/**
 * Answer-key matching (DESIGN §11.4; twin of ml/fuzzy/matching.py): input M
 * of the `short` system and the numeric lift of F in §11.3.
 *
 * - text, match_mode flexible: M = max(sim, key_match) where
 *   sim = 1 − levenshtein(norm(a), norm(k)) / max(len), best accepted answer;
 * - text, match_mode exact: M = 1 only when norm(a) equals an accepted
 *   answer; Gemini's key_match is ignored (spelling questions);
 * - numeric: M = 1 when |a − k| <= abs_tol, else 0.6 · clamp(1 − rel_err / 0.1)
 *   with rel_err = |a − k| / max(|k|, 1): a near miss never reaches "match".
 *
 * norm = trim, Thai digits -> Arabic digits, lower-case. Lengths and edit
 * distance count Unicode code points (Thai characters are 3 bytes in UTF-8,
 * so PHP's byte-based levenshtein() cannot be used).
 */
final class AnswerMatcher
{
    public const NEAR_MISS_CAP = 0.6;

    public const NEAR_MISS_REL_ERR = 0.1;

    private const THAI_DIGITS = ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9'];

    public static function normalize(string $text): string
    {
        $trimmed = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text);

        return mb_strtolower(strtr($trimmed, self::THAI_DIGITS), 'UTF-8');
    }

    /** Edit distance over code points with unit costs. */
    public static function levenshtein(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $x = mb_str_split($a, 1, 'UTF-8');
        $y = mb_str_split($b, 1, 'UTF-8');
        if ($x === []) {
            return count($y);
        }
        if ($y === []) {
            return count($x);
        }

        $previous = range(0, count($y));
        foreach ($x as $i => $ca) {
            $current = [$i + 1];
            foreach ($y as $j => $cb) {
                $cost = $ca === $cb ? 0 : 1;
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + $cost);
            }
            $previous = $current;
        }

        return $previous[count($y)];
    }

    /** 1 − levenshtein(norm(a), norm(k)) / max(len), in 0..1. */
    public static function similarity(string $answer, string $key): float
    {
        $a = self::normalize($answer);
        $k = self::normalize($key);
        $longest = max(mb_strlen($a, 'UTF-8'), mb_strlen($k, 'UTF-8'));
        if ($longest === 0) {
            return 1.0;
        }

        return 1.0 - self::levenshtein($a, $k) / $longest;
    }

    /**
     * @param  list<string>  $accepted
     */
    public static function textMatchFlexible(string $answer, array $accepted, ?string $keyMatch = null): float
    {
        if ($accepted === []) {
            throw new InvalidArgumentException('accepted answers must not be empty');
        }
        $best = max(array_map(fn (string $key) => self::similarity($answer, $key), $accepted));
        if ($keyMatch === null) {
            return $best;
        }

        return max($best, CategoryScale::matchValue($keyMatch, 'short'));
    }

    /**
     * @param  list<string>  $accepted
     */
    public static function textMatchExact(string $answer, array $accepted): float
    {
        if ($accepted === []) {
            throw new InvalidArgumentException('accepted answers must not be empty');
        }
        $a = self::normalize($answer);
        foreach ($accepted as $key) {
            if ($a === self::normalize($key)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    public static function numericMatch(float $answer, float $key, float $absTol = 0.0): float
    {
        if ($absTol < 0) {
            throw new InvalidArgumentException('abs_tol must be >= 0');
        }
        $diff = abs($answer - $key);
        if ($diff <= $absTol) {
            return 1.0;
        }
        $relErr = $diff / max(abs($key), 1.0);

        return self::NEAR_MISS_CAP * Membership::clamp(1.0 - $relErr / self::NEAR_MISS_REL_ERR);
    }

    /**
     * What a student may write in a numeric box: 12, -3.5, ๑๒.๕, 3,5, 3/4.
     * Null when the text is not a number (letters, empty, [?], x/0).
     */
    public static function parseNumber(string $text): ?float
    {
        $s = str_replace([' ', '−'], ['', '-'], self::normalize($text));
        if (preg_match('/^[+\-]?\d+(?:[.,]\d+)?$|^[+\-]?\d+\/\d+$/', $s) !== 1) {
            return null;
        }
        if (str_contains($s, '/')) {
            [$num, $den] = explode('/', $s);
            $d = (float) $den;
            if ($d == 0.0) {
                return null;
            }

            return (float) $num / $d;
        }

        return (float) str_replace(',', '.', $s);
    }

    /**
     * M for a short question from its answer_key (DESIGN §8.3):
     * {"accepted": [...]} or {"accepted": [...], "numeric": {"value", "abs_tol"}}.
     * A numeric key applies when the answer parses as a number; otherwise the
     * text rule of match_mode.
     *
     * @param  array<string, mixed>  $answerKey
     */
    public static function shortMatch(string $answerText, array $answerKey, string $matchMode = 'flexible', ?string $keyMatch = null): float
    {
        $numeric = $answerKey['numeric'] ?? null;
        if (is_array($numeric)) {
            $value = self::parseNumber($answerText);
            if ($value !== null) {
                return self::numericMatch($value, (float) $numeric['value'], (float) ($numeric['abs_tol'] ?? 0.0));
            }
        }

        $accepted = array_values(array_map('strval', (array) ($answerKey['accepted'] ?? [])));

        return match ($matchMode) {
            'exact' => self::textMatchExact($answerText, $accepted),
            'flexible' => self::textMatchFlexible($answerText, $accepted, $keyMatch),
            default => throw new InvalidArgumentException("unknown match_mode {$matchMode}"),
        };
    }

    /**
     * F for show_work (§11.3): Gemini's final_answer_match, lifted by the
     * numeric rule when the key is numeric: max(F, numeric_match).
     *
     * @param  array<string, mixed>|null  $finalKey  answer_key.final
     */
    public static function finalAnswerValue(string $finalAnswerMatch, ?string $finalAnswerText = null, ?array $finalKey = null): float
    {
        $f = CategoryScale::matchValue($finalAnswerMatch, 'show_work');
        if ($finalKey === null || $finalAnswerText === null) {
            return $f;
        }
        $numeric = $finalKey['numeric'] ?? null;
        if (! is_array($numeric)) {
            return $f;
        }
        $value = self::parseNumber($finalAnswerText);
        if ($value === null) {
            return $f;
        }

        return max($f, self::numericMatch($value, (float) $numeric['value'], (float) ($numeric['abs_tol'] ?? 0.0)));
    }
}
