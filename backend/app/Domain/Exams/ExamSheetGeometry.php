<?php

namespace App\Domain\Exams;

use App\Domain\Worksheets\WorksheetGeometry;

/**
 * Fixed geometry of an exam answer sheet in millimetres (DESIGN §22.7).
 *
 * The frame, the ArUco markers, the QR and the header lines are the
 * worksheet's (WorksheetGeometry, §5.2). Below the header rule the page is
 * a grid of 4 columns × 25 rows of bubble rows; a page may give its last
 * 11 or 22 rows to one or two bands of digit blocks (one numeric question
 * per column). Everything here is drawn by ExamSheetPdfRenderer and written
 * to the layout JSON by ExamSheetPlan from the same functions, so the app
 * never needs these numbers (§22.7, last paragraph).
 */
final class ExamSheetGeometry
{
    // Version bubbles (page 1, only when the exam has more than one version).
    public const VERSION_Y = 38.5;

    public const VERSION_X = 50.0;

    public const VERSION_STEP = 8.0;

    public const VERSION_R = 2.1;

    /** "ชุดข้อสอบ" label left of the version bubbles. */
    public const VERSION_LABEL_X = 26.0;

    // Grid of bubble rows.
    public const GRID_X = 18.0;

    public const COLUMN_W = 43.5;

    public const COLUMN_HEADER_Y = 50.0;

    public const FIRST_ROW_Y = 56.0;

    public const ROW_STEP = 8.0;

    /** Width of the right-aligned question number at the left of a column. */
    public const NUMBER_W = 8.0;

    /** Centre of bubble i is at column left + BUBBLE_OFFSET + BUBBLE_STEP × (i − 1). */
    public const BUBBLE_OFFSET = 11.0;

    public const BUBBLE_STEP = 6.0;

    public const BUBBLE_R = 2.1;

    public const BUBBLE_LINE = 0.25;

    /** Option labels are printed inside the bubbles in 35 % grey (DESIGN §22.7, §22.9 threshold 140). */
    public const LABEL_GREY = 166;

    // Digit blocks.
    public const BLOCK_HEADER_H = 12.0;

    public const DIGIT_ROWS = 11;

    public const DIGIT_ROW_STEP = 6.6;

    public const DIGIT_COL_STEP = 5.6;

    public const DIGIT_R = 2.0;

    /** Left margin of the first block column inside a grid column: (43.5 − 7 × 5.6) / 2. */
    public const BLOCK_MARGIN = 2.15;

    // Student-ID grid of a shared sheet (DESIGN §22.19): the first CODE_ROWS
    // grid rows of every page, columns of 0–9 with a box to write the digit in.
    public const CODE_MARGIN = 4.0;

    public const CODE_HEADER_H = 12.0;

    public const CODE_ROW_STEP = 6.0;

    public const CODE_COL_STEP = 5.6;

    public const CODE_R = 2.0;

    /** Left edge of the "how to fill in the ID" text, right of the widest grid. */
    public const CODE_HELP_X = 104.0;

    // Footer instructions.
    public const FOOTER_Y = 262.0;

    /** The Otsu area of the phone (§22.9): the grid and bands, clear of header and footer. */
    public const ANSWER_AREA = ['x' => 17.0, 'y' => 46.0, 'w' => 176.0, 'h' => 210.0];

    public static function columnLeft(int $column): float
    {
        return self::GRID_X + self::COLUMN_W * ($column - 1);
    }

    /** Centre y of grid row $row (1–25): row 25 is at 248. */
    public static function rowY(int $row): float
    {
        return self::FIRST_ROW_Y + self::ROW_STEP * ($row - 1);
    }

    /** Top of the slot of grid row $row, half a row above its centre. */
    public static function rowTop(int $row): float
    {
        return self::rowY($row) - self::ROW_STEP / 2;
    }

    /** Centre x of bubble $index (1–6) in grid column $column. */
    public static function bubbleX(int $column, int $index): float
    {
        return self::columnLeft($column) + self::BUBBLE_OFFSET + self::BUBBLE_STEP * ($index - 1);
    }

    public static function versionX(int $versionNo): float
    {
        return self::VERSION_X + self::VERSION_STEP * ($versionNo - 1);
    }

    /**
     * Top of digit band $band (0-based) on a page with $bands bands: the
     * bands take the last 11 × $bands rows of the grid.
     */
    public static function bandTop(int $bands, int $band): float
    {
        $firstRow = ExamSheetCapacity::ROWS - ExamSheetCapacity::BAND_ROWS * $bands + 1 + ExamSheetCapacity::BAND_ROWS * $band;

        return self::rowTop($firstRow);
    }

    public static function bandHeight(): float
    {
        return ExamSheetCapacity::BAND_ROWS * self::ROW_STEP;
    }

    /** Centre x of block column $col (1-based, sign column first when there is one). */
    public static function digitX(int $column, int $col): float
    {
        return self::columnLeft($column) + self::BLOCK_MARGIN + self::DIGIT_COL_STEP / 2 + self::DIGIT_COL_STEP * ($col - 1);
    }

    /** Centre y of bubble row $row (1 = "−" / ".", 2–11 = 0–9) of a block whose top is $top. */
    public static function digitY(float $top, int $row): float
    {
        return $top + self::BLOCK_HEADER_H + self::DIGIT_ROW_STEP / 2 + self::DIGIT_ROW_STEP * ($row - 1);
    }

    /** Top of the student-ID grid: the top of grid row 1. */
    public static function codeTop(): float
    {
        return self::rowTop(1);
    }

    /** Height of the grid's box: header, ten bubble rows and a margin; it ends above the option labels of row 11. */
    public static function codeHeight(): float
    {
        return self::CODE_HEADER_H + 10 * self::CODE_ROW_STEP + 2.0;
    }

    /** Bottom of the rows the grid reserves: the top of the first bubble row's slot. */
    public static function codeBandBottom(): float
    {
        return self::rowTop(ExamSheetCapacity::CODE_ROWS + 1);
    }

    public static function codeWidth(int $digits): float
    {
        return 2 * self::CODE_MARGIN + self::CODE_COL_STEP * $digits;
    }

    /** Centre x of ID column $col (1-based, most significant digit first). */
    public static function codeX(int $col): float
    {
        return self::GRID_X + self::CODE_MARGIN + self::CODE_COL_STEP / 2 + self::CODE_COL_STEP * ($col - 1);
    }

    /** Centre y of the bubble of digit $digit (0–9) in the ID grid. */
    public static function codeY(int $digit): float
    {
        return self::codeTop() + self::CODE_HEADER_H + self::CODE_ROW_STEP / 2 + self::CODE_ROW_STEP * $digit;
    }

    /** y of the option labels above the first bubble row, which is grid row $firstRow. */
    public static function columnHeaderY(int $firstRow): float
    {
        return self::rowTop($firstRow) - (self::rowTop(1) - self::COLUMN_HEADER_Y);
    }

    /**
     * @return array{x: float, y: float, w: float, h: float}
     */
    public static function answerArea(): array
    {
        $a = self::ANSWER_AREA;

        return WorksheetGeometry::rect($a['x'], $a['y'], $a['w'], $a['h']);
    }
}
