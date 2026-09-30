<?php

namespace App\Domain\Exams;

/**
 * Pages of an exam's answer sheet (DESIGN §22.7 "ความจุต่อหน้า"): a page
 * with b digit bands (0–2) holds 4 × (25 − 11b) bubble rows and 4b digit
 * blocks. Greedy: each page takes as many bands as the numeric questions
 * still need (at most 2) and fills the rest with rows. More than
 * MAX_PAGES pages is 422 exam_sheet_overflow when printing.
 */
final class ExamSheetCapacity
{
    public const MAX_PAGES = 2;

    public const COLUMNS = 4;

    public const ROWS = 25;

    public const BAND_ROWS = 11;

    public const MAX_BANDS = 2;

    /**
     * @return array{pages: int, overflow: bool}
     */
    public static function of(int $rowQuestions, int $numericQuestions): array
    {
        $pages = 0;
        while ($rowQuestions > 0 || $numericQuestions > 0) {
            $pages++;
            $bands = min(self::MAX_BANDS, intdiv($numericQuestions + self::COLUMNS - 1, self::COLUMNS));
            $numericQuestions -= min($numericQuestions, self::COLUMNS * $bands);
            $rowQuestions -= min($rowQuestions, self::COLUMNS * (self::ROWS - self::BAND_ROWS * $bands));
        }

        return ['pages' => $pages, 'overflow' => $pages > self::MAX_PAGES];
    }
}
