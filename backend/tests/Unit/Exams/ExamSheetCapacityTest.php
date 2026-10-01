<?php

namespace Tests\Unit\Exams;

use App\Domain\Exams\ExamSheetCapacity;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DESIGN §22.7 "ความจุต่อหน้า": 100 rows a page, digit bands of 4 blocks take
 * 11 rows each, bands follow the last bubble row, at most 2 pages.
 */
class ExamSheetCapacityTest extends TestCase
{
    /** @return array<string, array{int, int, int, bool}> */
    public static function sheets(): array
    {
        return [
            'empty' => [0, 0, 0, false],
            'one page of rows' => [100, 0, 1, false],
            'rows spill to page 2' => [101, 0, 2, false],
            'two full pages' => [200, 0, 2, false],
            'too many rows' => [201, 0, 3, true],
            'one band' => [56, 4, 1, false],
            'one band and a row too many' => [57, 4, 2, false],
            'two bands' => [12, 8, 1, false],
            'sixteen numeric' => [0, 16, 2, false],
            'seventeen numeric' => [0, 17, 3, true],
            'rows fill page 1, numeric on page 2' => [60, 5, 2, false],
            'full page of rows, two bands on page 2' => [100, 8, 2, false],
            'compact packing when question order needs three pages' => [60, 10, 2, false],
            'neither packing fits' => [150, 8, 3, true],
        ];
    }

    #[DataProvider('sheets')]
    public function test_pages_of_the_answer_sheet(int $rows, int $numeric, int $pages, bool $overflow): void
    {
        $this->assertSame(['pages' => $pages, 'overflow' => $overflow], ExamSheetCapacity::of($rows, $numeric));
    }

    public function test_bands_follow_the_last_bubble_row(): void
    {
        // 60 rows need 25 rows a column, so no band fits under them on page 1.
        $this->assertSame([
            ['rows' => 60, 'blocks' => 0, 'bands' => 0],
            ['rows' => 0, 'blocks' => 5, 'bands' => 2],
        ], ExamSheetCapacity::split(60, 5));
        // 40 rows fit 14 a column, so one band goes under them and the rest on page 2.
        $this->assertSame([
            ['rows' => 40, 'blocks' => 4, 'bands' => 1],
            ['rows' => 0, 'blocks' => 3, 'bands' => 1],
        ], ExamSheetCapacity::split(40, 7));
        // Rows past page 1: the bands go under the rows of page 2.
        $this->assertSame([
            ['rows' => 100, 'blocks' => 0, 'bands' => 0],
            ['rows' => 30, 'blocks' => 4, 'bands' => 1],
        ], ExamSheetCapacity::split(130, 4));
    }

    public function test_the_compact_packing_is_only_a_fallback(): void
    {
        $this->assertCount(3, ExamSheetCapacity::inOrder(60, 10));
        $this->assertSame([
            ['rows' => 12, 'blocks' => 8, 'bands' => 2],
            ['rows' => 48, 'blocks' => 2, 'bands' => 1],
        ], ExamSheetCapacity::split(60, 10));
        // When neither fits, the question-order split is reported (it is the one the teacher reads about).
        $this->assertSame(ExamSheetCapacity::inOrder(150, 8), ExamSheetCapacity::split(150, 8));
    }
}
