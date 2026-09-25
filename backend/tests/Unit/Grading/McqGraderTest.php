<?php

namespace Tests\Unit\Grading;

use App\Domain\Grading\McqGrader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DESIGN §11.6 multiple choice on upload. */
class McqGraderTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, float>, 1: float, 2: string, 3: float, 4: string}>
     */
    public static function cases(): array
    {
        return [
            'one bubble, correct' => [['A' => 0.04, 'B' => 0.83, 'C' => 0.06, 'D' => 0.05], 2.0, 'good', 0.0, 'confident'],
            'exactly at the threshold' => [['A' => 0.0, 'B' => 0.45, 'C' => 0.0, 'D' => 0.0], 2.0, 'good', 0.0, 'confident'],
            'one bubble, wrong' => [['A' => 0.9, 'B' => 0.1, 'C' => 0.0, 'D' => 0.0], 0.0, 'not_yet', 0.0, 'confident'],
            'two bubbles' => [['A' => 0.6, 'B' => 0.9, 'C' => 0.0, 'D' => 0.0], 0.0, 'not_yet', 1.0, 'check'],
            'faint second bubble' => [['A' => 0.25, 'B' => 0.9, 'C' => 0.0, 'D' => 0.0], 2.0, 'good', 0.6, 'check'],
            'nothing filled' => [['A' => 0.1, 'B' => 0.1, 'C' => 0.0, 'D' => 0.0], 0.0, 'not_yet', 0.0, 'confident'],
            'only a faint mark' => [['A' => 0.0, 'B' => 0.3, 'C' => 0.0, 'D' => 0.0], 0.0, 'not_yet', 0.6, 'check'],
        ];
    }

    /**
     * @param  array<string, float>  $fill
     */
    #[DataProvider('cases')]
    public function test_grading(array $fill, float $score, string $understanding, float $p, string $band): void
    {
        $grade = McqGrader::grade($fill, 'B', 2.0);

        $this->assertSame($score, $grade->score);
        $this->assertSame($understanding, $grade->understanding);
        $this->assertSame($p, $grade->reviewPriority);
        $this->assertSame($band, $grade->priorityBand);
        $this->assertSame('mcq', $grade->trace['system']);
    }

    public function test_a_blank_answer_is_tagged_no_answer(): void
    {
        $this->assertSame(['no_answer'], McqGrader::grade(['A' => 0.0, 'B' => 0.1], 'B', 1)->errorTypes);
        $this->assertSame([], McqGrader::grade(['A' => 0.9, 'B' => 0.1], 'B', 1)->errorTypes);
    }
}
