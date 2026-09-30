<?php

namespace App\Domain\Gradebook;

use App\Models\Course;

/**
 * The Thai 8-level grade scale (DESIGN §23.2, §23.6): seven whole-number
 * minimums, strictly decreasing within 1–100, for the grades 4, 3.5, 3,
 * 2.5, 2, 1.5 and 1; a rounded total below the last one is 0.
 */
final class GradeCutoffs
{
    public const GRADES = [4.0, 3.5, 3.0, 2.5, 2.0, 1.5, 1.0];

    public const COUNT = 7;

    /** @return list<int> */
    public static function defaults(): array
    {
        return array_map('intval', (array) config('eduvision.gradebook.default_cutoffs', [80, 75, 70, 65, 60, 55, 50]));
    }

    /** @return list<int> the course's cutoffs, or the defaults */
    public static function of(Course $course): array
    {
        $cutoffs = $course->grade_cutoffs;

        return is_array($cutoffs) && self::problem($cutoffs) === null ? array_map('intval', array_values($cutoffs)) : self::defaults();
    }

    /**
     * Why $cutoffs is not a valid scale (Thai), or null when it is.
     *
     * @param  array<mixed>  $cutoffs
     */
    public static function problem(array $cutoffs): ?string
    {
        if (! array_is_list($cutoffs) || count($cutoffs) !== self::COUNT) {
            return 'เกณฑ์เกรดต้องมี 7 ค่า (ขั้นต่ำของเกรด 4, 3.5, 3, 2.5, 2, 1.5 และ 1)';
        }
        $previous = 101;
        foreach ($cutoffs as $value) {
            if (! is_int($value) && ! (is_string($value) && preg_match('/^\d{1,3}$/', $value) === 1)
                && ! (is_float($value) && floor($value) === $value)) {
                return 'เกณฑ์เกรดต้องเป็นจำนวนเต็ม';
            }
            $value = (int) $value;
            if ($value < 1 || $value > 100) {
                return 'เกณฑ์เกรดต้องอยู่ในช่วง 1–100';
            }
            if ($value >= $previous) {
                return 'เกณฑ์เกรดต้องลดหลั่นจากเกรด 4 ลงไปเกรด 1 และห้ามซ้ำกัน';
            }
            $previous = $value;
        }

        return null;
    }

    /**
     * The grade of a rounded total.
     *
     * @param  list<int>  $cutoffs
     */
    public static function grade(int $rounded, array $cutoffs): float
    {
        foreach ($cutoffs as $i => $minimum) {
            if ($rounded >= $minimum) {
                return self::GRADES[$i];
            }
        }

        return 0.0;
    }

    /** "4", "3.5", …, "0", or "ร" / "มส" (§23.6). */
    public static function label(?float $grade, ?string $special): string
    {
        if ($special === 'r') {
            return 'ร';
        }
        if ($special === 'ms') {
            return 'มส';
        }
        if ($grade === null) {
            return '';
        }

        return floor($grade) === $grade ? (string) (int) $grade : number_format($grade, 1, '.', '');
    }
}
