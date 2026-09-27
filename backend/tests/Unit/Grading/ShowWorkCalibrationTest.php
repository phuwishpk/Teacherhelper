<?php

namespace Tests\Unit\Grading;

use App\Domain\Grading\ResponseGrader;
use App\Models\Question;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Calibration cases for show_work (DESIGN §11.3, §11.8, §11.9): extractions
 * seen from the real model, run through the whole grading path (numeric
 * near-miss F, step ratio S, fuzzy system 1 and 2) so a change to the rules
 * or to the extract prompt shows its effect on a known answer.
 */
class ShowWorkCalibrationTest extends TestCase
{
    /**
     * "มีดินสอ 3 กล่อง กล่องละ 12 แท่ง ..." key 36, 4 points: the student set up
     * 3 × 12 correctly, wrote 38 and answered "ตอบ 38 แท่ง" (one arithmetic slip).
     * F = 0.6 · (1 − (2/36)/0.1) = 0.267 for both rows (§11.4 numeric near-miss).
     *
     * @return array<string, array{list<bool>, float, float, string, float, float, string}>
     */
    public static function carriedForward(): array
    {
        return [
            // extract.show_work.v2: line 3 follows validly from the wrong line 2, S = 2/3.
            'error carried forward, line 3 valid (v2)' => [[true, false, true], 0.6373, 2.5, 'partial', 0.6, 0.0, 'confident'],
            // What gemini-3.8-flash answered with v1 (line 3 marked invalid), S = 1/3:
            // one slip dropped to 1.5 and not_yet, still in the confident band.
            'line 3 marked invalid (v1 reading)' => [[true, false, false], 0.3831, 1.5, 'not_yet', 0.3254, 0.1525, 'confident'],
        ];
    }

    /**
     * @param  list<bool>  $valid
     */
    #[DataProvider('carriedForward')]
    public function test_an_error_carried_forward(array $valid, float $ratio, float $score, string $understanding, float $u, float $p, string $band): void
    {
        $question = new Question([
            'type' => Question::TYPE_SHOW_WORK,
            'prompt_text' => 'มีดินสอ 3 กล่อง กล่องละ 12 แท่ง มีดินสอทั้งหมดกี่แท่ง แสดงวิธีทำ',
            'max_points' => 4,
            'answer_lines' => 4,
            'is_numeric' => true,
            'answer_key' => [
                'final' => ['accepted' => ['36'], 'numeric' => ['value' => 36, 'abs_tol' => 0]],
                'reference_steps' => ['ดินสอ 3 กล่อง กล่องละ 12 แท่ง', '3 × 12 = 36', 'ตอบ 36 แท่ง'],
            ],
        ]);
        $lines = ['มีดินสอ 3 กล่อง กล่องละ 12 แท่ง', '3 × 12 = 38', 'ตอบ 38 แท่ง'];
        $extraction = [
            'blank' => false,
            'suspicious_instruction' => false,
            'legibility' => 'clear',
            'steps' => array_map(fn (int $i) => ['line' => $i + 1, 'text' => $lines[$i], 'valid' => $valid[$i]], array_keys($lines)),
            'final_answer_text' => '38',
            'final_answer_match' => 'different',
            'error_types' => ['calculation'],
        ];

        $outcome = ResponseGrader::grade($question, [], 'normal', $extraction, cnnText: '38', inkRatio: 0.05);

        $this->assertEqualsWithDelta(0.2667, $outcome->trace['inputs']['F'], 0.0001);
        $this->assertEqualsWithDelta(array_sum($valid) / 3, $outcome->trace['inputs']['S'], 1e-9);
        $this->assertEqualsWithDelta($ratio, $outcome->scoreRatio, 0.0001);
        $this->assertSame($score, $outcome->score);
        $this->assertSame($understanding, $outcome->understanding);
        $this->assertEqualsWithDelta($u, $outcome->trace['u'], 0.0001);
        $this->assertEqualsWithDelta($p, $outcome->reviewPriority, 0.0001);
        $this->assertSame($band, $outcome->priorityBand);
    }
}
