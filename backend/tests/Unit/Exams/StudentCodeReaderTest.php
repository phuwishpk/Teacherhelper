<?php

namespace Tests\Unit\Exams;

use App\Domain\Exams\StudentCodeReader;
use PHPUnit\Framework\TestCase;

/**
 * DESIGN §22.19: the student ID read from the grid of a shared answer
 * sheet, by the golden fixture the app runs too
 * (app/test/fixtures/exam_scoring/codes.json).
 */
class StudentCodeReaderTest extends TestCase
{
    public function test_ids_match_the_shared_fixture(): void
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/exam_scoring/codes.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($data['cases']);
        foreach ($data['cases'] as $case) {
            $this->assertSame(
                ['code' => $case['code'], 'problem' => $case['problem']],
                StudentCodeReader::read(['sign' => null, 'columns' => $case['columns']]),
                $case['name'],
            );
        }
    }

    public function test_a_sheet_without_the_grid_reads_blank(): void
    {
        $this->assertSame(['code' => null, 'problem' => StudentCodeReader::BLANK], StudentCodeReader::read(null));
    }
}
