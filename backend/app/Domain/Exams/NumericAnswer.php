<?php

namespace App\Domain\Exams;

use App\Models\ExamSection;

/**
 * The canonical form of a numeric exam answer (DESIGN §22.3). The Dart
 * ExamSheetScorer must produce the same strings:
 *
 * - optional leading "-", then digits with at most one "." (".5" and "5." are fine);
 * - leading zeros of the integer part are dropped, keeping at least one "0";
 * - trailing zeros of the fraction are dropped, and the "." when no fraction is left;
 * - "-0" (and "-0.00") is "0".
 *
 * Answers compare as these strings; there is no tolerance (the teacher lists
 * several accepted values instead).
 */
final class NumericAnswer
{
    /** The canonical form, or null when the text is not a number of this form. */
    public static function canonical(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $text, $m) !== 1) {
            return null;
        }
        $negative = $m[1] === '-';
        $integer = $m[2];
        $fraction = $m[3] ?? '';
        if ($integer === '' && $fraction === '') {
            return null; // only a sign and/or a dot
        }

        $integer = ltrim($integer, '0');
        if ($integer === '') {
            $integer = '0';
        }
        $fraction = rtrim($fraction, '0');
        $value = $fraction === '' ? $integer : $integer.'.'.$fraction;

        return $negative && $value !== '0' ? '-'.$value : $value;
    }

    /**
     * Whether a canonical value can be bubbled in the digit block of the
     * section (DESIGN §22.7): a "-" needs the sign column, a fraction needs
     * the decimal option, and the digits plus the "." must fit the columns
     * (numeric_digits, plus one when decimals are allowed). A value below 1
     * may drop its leading "0" (".5").
     */
    public static function fits(string $canonical, ExamSection $section): bool
    {
        $negative = str_starts_with($canonical, '-');
        if ($negative && ! $section->numeric_allow_negative) {
            return false;
        }
        $unsigned = ltrim($canonical, '-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        if ($fraction !== '' && ! $section->numeric_allow_decimal) {
            return false;
        }
        if ($fraction !== '' && $integer === '0') {
            $integer = '';
        }
        $needed = strlen($integer) + ($fraction === '' ? 0 : 1 + strlen($fraction));
        $columns = (int) $section->numeric_digits + ($section->numeric_allow_decimal ? 1 : 0);

        return $needed >= 1 && $needed <= $columns;
    }
}
