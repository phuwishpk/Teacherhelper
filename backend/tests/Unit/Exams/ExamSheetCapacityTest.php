<?php

namespace Tests\Unit\Exams;

use App\Domain\Exams\ExamSheetCapacity;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** DESIGN §22.7 "ความจุต่อหน้า": 100 rows a page, digit bands of 4 blocks take 11 rows each, at most 2 pages. */
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
            'mixed on two pages' => [60, 10, 2, false],
        ];
    }

    #[DataProvider('sheets')]
    public function test_pages_of_the_answer_sheet(int $rows, int $numeric, int $pages, bool $overflow): void
    {
        $this->assertSame(['pages' => $pages, 'overflow' => $overflow], ExamSheetCapacity::of($rows, $numeric));
    }
}
