<?php

namespace App\Domain\Gemini;

/** Small formatting helpers shared by the prompt factories. */
final class PromptText
{
    /** 5.0 -> "5", 2.5 -> "2.5", 1.25 -> "1.25" */
    public static function number(float $value): string
    {
        $s = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }

    /**
     * ["x = 5", "5"] as one line, or $empty.
     *
     * @param  list<string>  $items
     */
    public static function quotedList(array $items, string $empty = '(none)'): string
    {
        $items = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $items), fn (string $s) => $s !== ''));

        return $items === [] ? $empty : implode(' | ', array_map(fn (string $s) => '"'.$s.'"', $items));
    }

    /**
     * "1. first\n2. second", or $empty.
     *
     * @param  list<string>  $items
     */
    public static function numberedLines(array $items, string $empty = '(none)'): string
    {
        $items = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $items), fn (string $s) => $s !== ''));
        if ($items === []) {
            return $empty;
        }

        return implode("\n", array_map(fn (string $s, int $i) => ($i + 1).'. '.$s, $items, array_keys($items)));
    }
}
