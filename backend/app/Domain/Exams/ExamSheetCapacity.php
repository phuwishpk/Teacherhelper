<?php

namespace App\Domain\Exams;

use App\Models\Assignment;

/**
 * Pages of an exam's answer sheet (DESIGN §22.7 "ความจุต่อหน้า"): a page
 * with b digit bands (0–2) holds 4 × (25 − 11b) bubble rows and 4b digit
 * blocks.
 *
 * The sheet follows question order: bubble rows fill pages of 100 first,
 * then the digit blocks go below the last bubble row, on the same page as
 * far as the bands fit under its rows, the rest on the next page. Only when
 * that order needs more than MAX_PAGES pages and the compact packing (every
 * page takes the bands it can, bands first) fits, the compact packing is
 * used. More than MAX_PAGES pages is 422 exam_sheet_overflow when printing.
 *
 * A sheet with the student-ID grid (DESIGN §22.19) gives the first
 * CODE_ROWS rows of every page to that grid: $reserved = CODE_ROWS, so a
 * page holds 4 × (15 − 11b) rows and at most one digit band.
 */
final class ExamSheetCapacity
{
    public const MAX_PAGES = 2;

    public const COLUMNS = 4;

    public const ROWS = 25;

    public const BAND_ROWS = 11;

    public const MAX_BANDS = 2;

    /** Grid rows at the top of every page of a sheet with the student-ID grid (§22.19). */
    public const CODE_ROWS = 10;

    /**
     * @return array{pages: int, overflow: bool}
     */
    public static function of(int $rowQuestions, int $numericQuestions, int $reserved = 0): array
    {
        $pages = count(self::split($rowQuestions, $numericQuestions, $reserved));

        return ['pages' => $pages, 'overflow' => $pages > self::MAX_PAGES];
    }

    /**
     * What each page holds, page by page (the list may be longer than
     * MAX_PAGES; the caller decides about the overflow).
     *
     * @return list<array{rows: int, blocks: int, bands: int}>
     */
    public static function split(int $rowQuestions, int $numericQuestions, int $reserved = 0): array
    {
        $ordered = self::inOrder($rowQuestions, $numericQuestions, $reserved);
        if (count($ordered) <= self::MAX_PAGES) {
            return $ordered;
        }
        $compact = self::compact($rowQuestions, $numericQuestions, $reserved);

        return count($compact) <= self::MAX_PAGES ? $compact : $ordered;
    }

    /**
     * Question order: rows first (100 a page), the last page of rows takes
     * as many bands as fit under its rows, then pages of bands only.
     *
     * @return list<array{rows: int, blocks: int, bands: int}>
     */
    public static function inOrder(int $rowQuestions, int $numericQuestions, int $reserved = 0): array
    {
        $pages = [];
        while ($rowQuestions > 0) {
            $rows = min($rowQuestions, self::rowCapacity(0, $reserved));
            $rowQuestions -= $rows;
            $bands = 0;
            if ($rowQuestions === 0) {
                $bands = self::bandsFor($numericQuestions, $reserved);
                while ($bands > 0 && self::rowCapacity($bands, $reserved) < $rows) {
                    $bands--;
                }
            }
            $blocks = min($numericQuestions, self::COLUMNS * $bands);
            $numericQuestions -= $blocks;
            $pages[] = ['rows' => $rows, 'blocks' => $blocks, 'bands' => $bands];
        }
        while ($numericQuestions > 0) {
            $bands = self::bandsFor($numericQuestions, $reserved);
            $blocks = min($numericQuestions, self::COLUMNS * $bands);
            $numericQuestions -= $blocks;
            $pages[] = ['rows' => 0, 'blocks' => $blocks, 'bands' => $bands];
        }

        return $pages;
    }

    /**
     * The compact packing: each page takes as many bands as the numeric
     * questions still need (at most 2) and fills the rest with rows.
     *
     * @return list<array{rows: int, blocks: int, bands: int}>
     */
    public static function compact(int $rowQuestions, int $numericQuestions, int $reserved = 0): array
    {
        $pages = [];
        while ($rowQuestions > 0 || $numericQuestions > 0) {
            $bands = self::bandsFor($numericQuestions, $reserved);
            $blocks = min($numericQuestions, self::COLUMNS * $bands);
            $rows = min($rowQuestions, self::rowCapacity($bands, $reserved));
            $numericQuestions -= $blocks;
            $rowQuestions -= $rows;
            $pages[] = ['rows' => $rows, 'blocks' => $blocks, 'bands' => $bands];
        }

        return $pages;
    }

    /** Bands the numeric questions still need, at most the page's maxBands(). */
    private static function bandsFor(int $numericQuestions, int $reserved = 0): int
    {
        return min(self::maxBands($reserved), intdiv($numericQuestions + self::COLUMNS - 1, self::COLUMNS));
    }

    /** Digit bands that fit under $reserved rows: 2, or 1 below the student-ID grid. */
    public static function maxBands(int $reserved = 0): int
    {
        return min(self::MAX_BANDS, intdiv(self::ROWS - $reserved, self::BAND_ROWS));
    }

    /** Bubble rows of one grid column on a page with $bands digit bands. */
    public static function rowsPerColumn(int $bands, int $reserved = 0): int
    {
        return self::ROWS - $reserved - self::BAND_ROWS * $bands;
    }

    /** Bubble rows of a page with $bands digit bands: 4 × (25 − 11b), less the reserved rows. */
    public static function rowCapacity(int $bands, int $reserved = 0): int
    {
        return self::COLUMNS * self::rowsPerColumn($bands, $reserved);
    }

    /** Rows the exam's sheet reserves at the top of every page (§22.19). */
    public static function reservedRows(Assignment $exam): int
    {
        return $exam->usesCodeSheets() ? self::CODE_ROWS : 0;
    }
}
