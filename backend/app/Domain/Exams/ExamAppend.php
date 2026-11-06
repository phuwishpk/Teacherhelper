<?php

namespace App\Domain\Exams;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionOption;

/**
 * Adds many sections and questions to an exam at once (reading an exam
 * file, copying questions, DESIGN §22.4): new sections go after the last
 * one and new questions after the last question of their section, each
 * with a temporary number past the end; finish() renumbers the exam once,
 * rebuilds the shuffled versions and keeps the key approval valid. Callers
 * hold the assignment row lock and checked that the structure is unlocked.
 */
final class ExamAppend
{
    private int $nextPosition;

    public function __construct(private readonly Assignment $exam)
    {
        $this->nextPosition = (int) Question::query()->where('assignment_id', $exam->id)->max('position') + 1;
    }

    public function questionRoom(): int
    {
        return max(0, (int) config('eduvision.exams.max_questions') - Question::query()->where('assignment_id', $this->exam->id)->count());
    }

    public function sectionRoom(): int
    {
        return max(0, (int) config('eduvision.exams.max_sections') - ExamSection::query()->where('assignment_id', $this->exam->id)->count());
    }

    /**
     * @param  array<string, mixed>  $attributes  type, option_count, numeric_*, title, instructions, default_points
     */
    public function section(array $attributes): ExamSection
    {
        if ($this->sectionRoom() < 1) {
            throw new ApiException('ข้อสอบหนึ่งฉบับมีได้ไม่เกิน '.config('eduvision.exams.max_sections').' ตอน', 'validation_failed', 422, ['section_id' => ['ข้อสอบมีตอนครบจำนวนสูงสุดแล้ว']]);
        }

        return ExamSection::create([
            ...$attributes,
            'assignment_id' => $this->exam->id,
            'position' => ExamSection::query()->where('assignment_id', $this->exam->id)->count() + 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes  question columns (prompt_text, max_points, answer_key, origin, approved_at, …)
     * @param  array<int, array<string, mixed>>  $options  position => option columns (text, image_path, figure_source)
     */
    public function question(ExamSection $section, array $attributes, array $options = []): Question
    {
        $question = Question::create([
            'lock_options' => false,
            ...$attributes,
            'assignment_id' => $this->exam->id,
            'section_id' => $section->id,
            'type' => $section->type,
            'position' => $this->nextPosition++,
            'is_numeric' => false,
            'match_mode' => 'exact',
            'rubric_status' => Question::RUBRIC_NOT_NEEDED,
        ]);
        if ($section->type === ExamSection::TYPE_MCQ) {
            for ($p = 1; $p <= (int) $section->option_count; $p++) {
                QuestionOption::create(['question_id' => $question->id, 'position' => $p, ...($options[$p] ?? [])]);
            }
        }

        return $question;
    }

    public function finish(): void
    {
        ExamPositions::renumber($this->exam->id);
        ExamVersions::sync($this->exam);
        ExamKeyCheck::keepApprovalValid($this->exam);
    }
}
