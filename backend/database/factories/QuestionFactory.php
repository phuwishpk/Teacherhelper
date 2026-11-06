<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an mcq question; the type states build valid answer keys (DESIGN §8.3).
 *
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory(),
            'position' => fn (array $attributes) => (int) Question::query()
                ->where('assignment_id', $attributes['assignment_id'])->max('position') + 1,
            'type' => Question::TYPE_MCQ,
            'prompt_text' => 'ข้อใดคือผลบวกของ 1/2 และ 1/4',
            'max_points' => 1,
            'answer_lines' => null,
            'is_numeric' => false,
            'match_mode' => 'flexible',
            'answer_key' => ['correct' => 'B'],
            'rubric_status' => Question::RUBRIC_NOT_NEEDED,
        ];
    }

    public function short(bool $numeric = true): static
    {
        return $this->state(fn () => [
            'type' => Question::TYPE_SHORT,
            'prompt_text' => '12.5 + 7.5 เท่ากับเท่าไร',
            'max_points' => 2,
            'is_numeric' => $numeric,
            'answer_key' => $numeric
                ? ['accepted' => ['20'], 'numeric' => ['value' => 20, 'abs_tol' => 0]]
                : ['accepted' => ['กรุงเทพมหานคร', 'กรุงเทพฯ']],
        ]);
    }

    public function showWork(string $rubricStatus = Question::RUBRIC_APPROVED, int $lines = 4): static
    {
        return $this->state(fn () => [
            'type' => Question::TYPE_SHOW_WORK,
            'prompt_text' => 'จงหาค่า x จากสมการ 3x + 5 = 20 แสดงวิธีทำ',
            'max_points' => 5,
            'answer_lines' => $lines,
            'is_numeric' => true,
            'answer_key' => [
                'final' => ['accepted' => ['x = 5', '5'], 'numeric' => ['value' => 5, 'abs_tol' => 0]],
                'reference_steps' => ['3x + 5 = 20', '3x = 15', 'x = 5'],
            ],
            'rubric_status' => $rubricStatus,
        ]);
    }

    public function open(string $rubricStatus = Question::RUBRIC_APPROVED, int $lines = 5): static
    {
        return $this->state(fn () => [
            'type' => Question::TYPE_OPEN,
            'prompt_text' => 'อธิบายว่าทำไมใบไม้จึงมีสีเขียว',
            'max_points' => 4,
            'answer_lines' => $lines,
            'is_numeric' => false,
            'answer_key' => null,
            'rubric_status' => $rubricStatus,
        ]);
    }
}
