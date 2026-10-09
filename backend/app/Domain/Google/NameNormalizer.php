<?php

namespace App\Domain\Google;

use Normalizer;

/**
 * Normalises a student's name so the name in Google Classroom and the name
 * the teacher typed into Krucheck compare equal (DESIGN §18.6 roster
 * suggestions): Unicode NFC, lower case, Thai and English titles removed
 * (ด.ช., เด็กหญิง, นาย, Mr. ...), Thai and Arabic digits unified, and
 * everything but letters, marks and digits dropped.
 */
final class NameNormalizer
{
    /** Longest first, so "เด็กชาย" is removed before "นาย" could match inside it. */
    private const TITLES = [
        'เด็กชาย', 'เด็กหญิง', 'นางสาว', 'ด.ช.', 'ด.ญ.', 'ดช.', 'ดญ.', 'น.ส.', 'นาย', 'นาง',
        'master', 'miss', 'mrs.', 'mrs', 'mr.', 'mr', 'ms.', 'ms',
    ];

    /**
     * @return list<string> name tokens without titles, each normalised
     */
    public static function tokens(string $name): array
    {
        $name = class_exists(Normalizer::class) ? (Normalizer::normalize($name, Normalizer::FORM_C) ?: $name) : $name;
        $name = mb_strtolower(trim($name));
        $name = strtr($name, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
        $name = self::stripTitle($name);

        $tokens = [];
        foreach (preg_split('/[\s,]+/u', $name) ?: [] as $part) {
            $part = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', $part) ?? '';
            if ($part !== '') {
                $tokens[] = $part;
            }
        }

        return $tokens;
    }

    /**
     * The name in NFC, lower case and trimmed, without its title, spacing
     * and punctuation kept (ThaiNameSorter sorts on this).
     */
    public static function withoutTitle(string $name): string
    {
        $name = class_exists(Normalizer::class) ? (Normalizer::normalize($name, Normalizer::FORM_C) ?: $name) : $name;

        return trim(self::stripTitle(mb_strtolower(trim($name))));
    }

    /** The whole name as one comparable string ('' when nothing is left). */
    public static function key(string $name): string
    {
        return implode('', self::tokens($name));
    }

    /** The tokens in sorted order: "Somchai Jaidee" equals "Jaidee Somchai". */
    public static function unorderedKey(string $name): string
    {
        $tokens = self::tokens($name);
        sort($tokens);

        return implode(' ', $tokens);
    }

    public static function firstName(string $name): string
    {
        return self::tokens($name)[0] ?? '';
    }

    private static function stripTitle(string $name): string
    {
        foreach (self::TITLES as $title) {
            if (str_starts_with($name, $title)) {
                $rest = substr($name, strlen($title));
                // An English title must end at a word boundary ("mr" but not "mrinal").
                if (preg_match('/\A[a-z]/', $title) === 1 && ! str_ends_with($title, '.') && preg_match('/\A[\s.]/u', $rest) !== 1) {
                    continue;
                }

                return ltrim($rest, " \t.");
            }
        }

        return $name;
    }
}
