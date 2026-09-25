<?php

namespace App\Domain\Assignments;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Question;

/**
 * Creates, edits and deletes questions (DESIGN §9.3).
 *
 * - show_work and open start with rubric_status `draft`; mcq/short `not_needed`.
 * - A change that alters the printed page (add, delete, reorder, type, prompt,
 *   points, answer lines, numeric box) sends a `ready` assignment back to
 *   `draft`; the next layout build creates a new layout_version (DESIGN §5.1).
 * - Changing the type discards the rubric; changing max_points of an approved
 *   rubric sends it back to `draft` because its points no longer add up.
 * - A question that already has scanned responses cannot be deleted (409).
 */
class QuestionEditor
{
    /** Attributes that change the printed page. */
    private const LAYOUT_FIELDS = ['type', 'prompt_text', 'max_points', 'answer_lines', 'is_numeric'];

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Assignment $assignment, array $input): Question
    {
        return AssignmentLocked::run($assignment->id, function (Assignment $assignment) use ($input) {
            $count = Question::query()->where('assignment_id', $assignment->id)->count();
            if ($count >= QuestionData::MAX_QUESTIONS) {
                throw new ApiException('การบ้านหนึ่งชุดมีได้ไม่เกิน '.QuestionData::MAX_QUESTIONS.' ข้อ', 'too_many_questions', 422);
            }

            $data = QuestionData::validate($input, $assignment);
            $question = Question::create([
                ...$data['attributes'],
                'assignment_id' => $assignment->id,
                'position' => QuestionPositions::next($assignment),
                'rubric_status' => Question::typeNeedsRubric($data['attributes']['type'])
                    ? Question::RUBRIC_DRAFT
                    : Question::RUBRIC_NOT_NEEDED,
            ]);

            if ($data['position'] !== null && $data['position'] < $question->position) {
                QuestionPositions::move($question, $data['position']);
            }
            $question->skills()->sync($data['skill_ids'] ?? []);
            $assignment->backToDraft();

            return $question;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  PATCH body; absent fields keep their value
     */
    public function update(Question $question, array $changes): Question
    {
        return AssignmentLocked::run($question->assignment_id, function (Assignment $assignment) use ($question, $changes) {
            $question = Question::query()->findOrFail($question->id);

            $merged = [
                'type' => $question->type,
                'prompt_text' => $question->prompt_text,
                'max_points' => $question->max_points,
                'answer_lines' => $question->answer_lines,
                'is_numeric' => $question->is_numeric,
                'match_mode' => $question->match_mode,
                'answer_key' => $question->answer_key,
                ...array_intersect_key($changes, array_flip(QuestionData::FIELDS)),
            ];
            $data = QuestionData::validate($merged, $assignment);
            $attributes = $data['attributes'];

            $typeChanged = $attributes['type'] !== $question->type;
            $pointsChanged = abs($attributes['max_points'] - $question->max_points) > 0.0001;

            $question->fill($attributes);
            $layoutChanged = $question->isDirty(self::LAYOUT_FIELDS);

            if ($typeChanged) {
                $question->rubricCriteria()->delete();
                $question->rubric_status = Question::typeNeedsRubric($attributes['type'])
                    ? Question::RUBRIC_DRAFT
                    : Question::RUBRIC_NOT_NEEDED;
            } elseif ($pointsChanged && $question->rubric_status === Question::RUBRIC_APPROVED) {
                $question->rubric_status = Question::RUBRIC_DRAFT;
            }
            $question->save();

            if ($data['position'] !== null && $data['position'] !== $question->position) {
                QuestionPositions::move($question, $data['position']);
                $layoutChanged = true;
            }
            if ($data['skill_ids'] !== null) {
                $question->skills()->sync($data['skill_ids']);
            }

            if ($layoutChanged || ! $question->rubricApproved()) {
                $assignment->backToDraft();
            }

            return $question;
        });
    }

    public function delete(Question $question): void
    {
        AssignmentLocked::run($question->assignment_id, function (Assignment $assignment) use ($question) {
            // Scanned answers keep their scores and review history (score_events),
            // so a question with responses stays; the teacher closes the assignment instead.
            if ($question->responses()->exists()) {
                throw new ApiException('ข้อนี้มีคำตอบของนักเรียนที่สแกนแล้ว ลบไม่ได้', 'question_has_responses', 409);
            }
            Question::query()->whereKey($question->id)->delete();
            QuestionPositions::compact($assignment->id);
            $assignment->backToDraft();
        });
    }
}
