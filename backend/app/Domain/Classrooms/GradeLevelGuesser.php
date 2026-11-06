<?php

namespace App\Domain\Classrooms;

/**
 * Guesses classrooms.grade_level (ป.1 = 1 ... ม.6 = 12) from a Google
 * Classroom course name and section (DESIGN §19.2 import preview):
 * "ป.4", "ป 4", "ประถมศึกษาปีที่ 4" -> 4; "ม.2/3", "มัธยมศึกษาปีที่ 2" -> 8.
 * Thai digits count. No match -> null, and the teacher must choose.
 *
 * The short forms must not follow another Thai letter, so the ม that ends
 * "สังคม 1" is not read as ม.1.
 */
final class GradeLevelGuesser
{
    private const PATTERNS = [
        ['/ประถม(?:ศึกษา)?\s*(?:ปี\s*ที่)?\s*([1-6])(?![0-9])/u', 0],
        ['/มัธยม(?:ศึกษา)?\s*(?:ปี\s*ที่)?\s*([1-6])(?![0-9])/u', 6],
        ['/(?<!\p{Thai})ป\s*\.?\s*([1-6])(?![0-9])/u', 0],
        ['/(?<!\p{Thai})ม\s*\.?\s*([1-6])(?![0-9])/u', 6],
    ];

    /** The first text that names a grade wins (course name before section). */
    public static function guess(?string ...$texts): ?int
    {
        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }
            $text = strtr($text, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
            foreach (self::PATTERNS as [$pattern, $offset]) {
                if (preg_match($pattern, $text, $m) === 1) {
                    return $offset + (int) $m[1];
                }
            }
        }

        return null;
    }
}
