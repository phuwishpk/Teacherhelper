<?php

namespace App\Domain\Gemini;

use App\Models\Question;

/**
 * Input of the `rubric_draft` prompt (DESIGN §10.4). teacherId is the owner of
 * the classroom: the real client uses that teacher's API key when one is set.
 */
final readonly class RubricDraftRequest
{
    public function __construct(
        public int $questionId,
        public int $teacherId,
        public string $type,
        public string $subject,
        public string $gradeLabel,
        public float $maxPoints,
        public string $promptText,
        public string $answerKeyText,
    ) {}

    public static function fromQuestion(Question $question): self
    {
        $assignment = $question->assignment()->with(['classroom', 'subject'])->firstOrFail();

        return new self(
            questionId: $question->id,
            teacherId: $assignment->classroom->teacher_id,
            type: $question->type,
            subject: $assignment->subject->name,
            gradeLabel: self::gradeLabel($assignment->classroom->grade_level),
            maxPoints: $question->max_points,
            promptText: $question->prompt_text,
            answerKeyText: self::answerKeyText($question),
        );
    }

    /** 1–6 -> ป.1–ป.6, 7–12 -> ม.1–ม.6 (classrooms.grade_level, DESIGN §8.1). */
    public static function gradeLabel(int $gradeLevel): string
    {
        return $gradeLevel <= 6 ? 'ป.'.$gradeLevel : 'ม.'.($gradeLevel - 6);
    }

    private static function answerKeyText(Question $question): string
    {
        $key = $question->answer_key ?? [];

        if ($question->type === Question::TYPE_SHOW_WORK) {
            $final = implode(' หรือ ', $key['final']['accepted'] ?? []);
            $steps = $key['reference_steps'] ?? [];

            return 'คำตอบสุดท้าย: '.($final !== '' ? $final : '-')
                .($steps !== [] ? "\nขั้นตอนที่ครูเขียนไว้: ".implode(' | ', $steps) : '');
        }

        return '(ไม่มีเฉลยตายตัว ใช้เกณฑ์การให้คะแนน)';
    }
}
