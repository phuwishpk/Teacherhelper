<?php

namespace Tests\Unit\Exams;

use App\Domain\Exams\ExamAnswerScore;
use App\Models\Question;
use PHPUnit\Framework\TestCase;

/**
 * DESIGN §22.3, §22.11: a stored exam answer scored against the current
 * key, the teacher's reading (resolved) taking the place of the bubbles.
 */
class ExamAnswerScoreTest extends TestCase
{
    private static function question(string $type, array $key, float $points = 2): Question
    {
        $question = new Question;
        $question->forceFill(['type' => $type, 'answer_key' => $key, 'max_points' => $points]);

        return $question;
    }

    public function test_one_accepted_mark_gets_full_points_and_anything_else_zero(): void
    {
        $mcq = self::question(Question::TYPE_MCQ, ['accepted_options' => [2, 4]]);

        $this->assertSame(2.0, ExamAnswerScore::of($mcq, ['selected' => [4], 'value' => null])['score']);
        $this->assertSame(0.0, ExamAnswerScore::of($mcq, ['selected' => [2, 4], 'value' => null])['score']);
        $this->assertSame(0.0, ExamAnswerScore::of($mcq, ['selected' => [1], 'value' => null])['score']);
        $blank = ExamAnswerScore::of($mcq, ['selected' => [], 'value' => null]);
        $this->assertSame(0.0, $blank['score']);
        $this->assertTrue($blank['blank']);
        $this->assertSame('not_yet', $blank['understanding']);
    }

    public function test_the_teachers_reading_replaces_the_bubbles(): void
    {
        $mcq = self::question(Question::TYPE_MCQ, ['accepted_options' => [1]]);
        $answer = ['selected' => [1, 2], 'value' => null, 'resolved' => ['selected' => [1], 'value' => null]];

        $this->assertSame(['selected' => [1], 'value' => null, 'resolved' => true], ExamAnswerScore::effective($answer));
        $this->assertSame(2.0, ExamAnswerScore::of($mcq, $answer)['score']);
        $this->assertSame('good', ExamAnswerScore::of($mcq, $answer)['understanding']);
        $this->assertFalse(ExamAnswerScore::effective(['selected' => [1, 2]])['resolved']);
    }

    public function test_numbers_compare_in_canonical_form(): void
    {
        $numeric = self::question(Question::TYPE_NUMERIC, ['accepted_values' => ['0.5', '-3']], 1);

        $this->assertSame(1.0, ExamAnswerScore::of($numeric, ['selected' => [], 'value' => '0.5'])['score']);
        $this->assertSame(1.0, ExamAnswerScore::of($numeric, ['selected' => [], 'value' => '-3'])['score']);
        $this->assertSame(0.0, ExamAnswerScore::of($numeric, ['selected' => [], 'value' => '3'])['score']);
        $this->assertTrue(ExamAnswerScore::of($numeric, ['selected' => [], 'value' => null])['blank']);
        $this->assertSame(1.0, ExamAnswerScore::of($numeric, ['value' => null, 'resolved' => ['selected' => [], 'value' => '0.5']])['score']);
    }
}
