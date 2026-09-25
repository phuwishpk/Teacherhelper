<?php

namespace Tests\Feature\Worksheets;

use App\Models\Assignment;
use App\Models\Question;

/** Unsaved assignments and questions for layout and render tests. */
trait WorksheetFixtures
{
    private int $nextQuestionId = 500;

    /**
     * @param  list<Question>  $questions
     */
    protected function assignmentWith(array $questions, int $id = 123): Assignment
    {
        $assignment = new Assignment(['title' => 'เศษส่วน ชุดที่ 3']);
        $assignment->id = $id;
        $assignment->setRelation('questions', collect($questions));

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function question(string $type, array $attributes = []): Question
    {
        $defaults = match ($type) {
            'mcq' => ['prompt_text' => 'ข้อใดคือผลบวกของ 1/2 และ 1/4', 'max_points' => 1, 'answer_key' => ['correct' => 'B']],
            'short' => ['prompt_text' => '12.5 + 7.5 เท่ากับเท่าไร', 'max_points' => 2, 'is_numeric' => true],
            'show_work' => ['prompt_text' => 'จงหาค่า x จากสมการ 3x + 5 = 20 แสดงวิธีทำ', 'max_points' => 5, 'answer_lines' => 4, 'is_numeric' => true],
            'open' => ['prompt_text' => 'อธิบายว่าทำไมใบไม้จึงมีสีเขียว', 'max_points' => 4, 'answer_lines' => 5],
        };

        $question = new Question(['type' => $type, ...$defaults, ...$attributes]);
        $question->id = ++$this->nextQuestionId;

        return $question;
    }

    protected function longThaiPrompt(int $repeat): string
    {
        return str_repeat('นักเรียนสามารถตรวจคำตอบโดยการแทนค่ากลับลงในสมการเดิมเพื่อยืนยันว่าคำตอบถูกต้อง ', $repeat);
    }
}
