<?php

namespace App\Domain\Classrooms;

use App\Domain\Google\NameNormalizer;

/**
 * Orders students for their student numbers (เลขที่) when a classroom is
 * imported from Google Classroom (DESIGN §19.2), in plain PHP: the `intl`
 * extension (Collator) cannot be confirmed on the shared host.
 *
 *   1. The title is ignored (the NameNormalizer list: ด.ช., เด็กหญิง, นาย,
 *      Mr., Miss ...); the stored name keeps it.
 *   2. Thai names first, in dictionary order: a leading vowel เ แ โ ใ ไ at
 *      the start of a word sorts after the consonant that follows it, then
 *      code points decide (the Thai block is in dictionary order already).
 *      Equal first names compare the surname by the same rule.
 *   3. English names next, A-Z ignoring case; names that start with anything
 *      else come last.
 *
 * Ties (the same name twice) fall back to the full original name, then to
 * the caller's tie-breaker, so the order is always the same.
 */
final class ThaiNameSorter
{
    private const THAI = 0;

    private const LATIN = 1;

    private const OTHER = 2;

    /**
     * @template T of array<string, mixed>
     *
     * @param  list<T>  $rows
     * @param  string  $nameField  the field that holds the name
     * @param  string|null  $tieField  a field that is unique per row (e.g. google_user_id)
     * @return list<T>
     */
    public static function sort(array $rows, string $nameField = 'name', ?string $tieField = null): array
    {
        $keys = [];
        foreach ($rows as $i => $row) {
            $keys[$i] = self::key((string) $row[$nameField]);
        }

        $order = array_keys($rows);
        usort($order, function (int $a, int $b) use ($rows, $keys, $nameField, $tieField) {
            return self::compareKeys($keys[$a], $keys[$b])
                ?: strcmp((string) $rows[$a][$nameField], (string) $rows[$b][$nameField])
                ?: ($tieField !== null ? strcmp((string) $rows[$a][$tieField], (string) $rows[$b][$tieField]) : 0)
                ?: $a <=> $b;
        });

        return array_map(fn (int $i) => $rows[$i], $order);
    }

    /** <0, 0 or >0 like strcmp, by the rules above (no tie-breaker). */
    public static function compare(string $a, string $b): int
    {
        return self::compareKeys(self::key($a), self::key($b));
    }

    /**
     * @return array{0: int, 1: string, 2: string} [script group, first name key, surname key]
     */
    public static function key(string $name): array
    {
        $words = preg_split('/\s+/u', NameNormalizer::withoutTitle($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $words[0] ?? '';

        return [
            self::group($first),
            self::wordKey($first),
            implode(' ', array_map(self::wordKey(...), array_slice($words, 1))),
        ];
    }

    /**
     * @param  array{0: int, 1: string, 2: string}  $a
     * @param  array{0: int, 1: string, 2: string}  $b
     */
    private static function compareKeys(array $a, array $b): int
    {
        // strcmp on UTF-8 bytes follows code point order.
        return ($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1]) ?: strcmp($a[2], $b[2]);
    }

    private static function group(string $word): int
    {
        if (preg_match('/\A\p{Thai}/u', $word) === 1) {
            return self::THAI;
        }

        return preg_match('/\A[a-z]/', $word) === 1 ? self::LATIN : self::OTHER;
    }

    /** "เสือ" -> "สเือ": a leading vowel sorts after the next character. */
    private static function wordKey(string $word): string
    {
        return preg_replace('/\A([\x{0E40}-\x{0E44}])(.)/u', '$2$1', $word) ?? $word;
    }
}
