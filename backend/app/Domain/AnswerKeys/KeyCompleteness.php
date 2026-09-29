<?php

namespace App\Domain\AnswerKeys;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Whether an assignment's answer key is complete enough to grade (DESIGN
 * §19.5 "ทุกข้อมีเฉลยหรือ rubric ครบ"): at least one question, and every
 * question has
 *   mcq        the correct option
 *   short      at least one accepted answer
 *   show_work  an accepted final answer and an approved rubric
 *   open       an approved rubric (criteria)
 *
 * Checked when the teacher approves the key, when a worksheet layout is
 * built, and when a question edit might leave a ready freeform assignment
 * without a complete key.
 */
final class KeyCompleteness
{
    /**
     * Positions of the questions that are not complete; [0] when there is no question at all.
     *
     * @param  Collection<int, Question>|null  $questions  defaults to the assignment's questions
     * @return list<int>
     */
    public static function missing(Assignment $assignment, ?Collection $questions = null): array
    {
        $questions ??= Question::query()->where('assignment_id', $assignment->id)->orderBy('position')->get();
        if ($questions->isEmpty()) {
            return [0];
        }

        return $questions->reject(fn (Question $q) => self::complete($q))->pluck('position')->map(fn ($p) => (int) $p)->values()->all();
    }

    public static function complete(Question $question): bool
    {
        return self::hasKey($question) && $question->rubricApproved();
    }

    public static function hasKey(Question $question): bool
    {
        $key = $question->answer_key;

        return match ($question->type) {
            Question::TYPE_MCQ => in_array($key['correct'] ?? null, Question::MCQ_OPTIONS, true),
            Question::TYPE_SHORT => ($key['accepted'] ?? []) !== [],
            Question::TYPE_SHOW_WORK => ($key['final']['accepted'] ?? []) !== [],
            default => true,
        };
    }

    /**
     * @param  Collection<int, Question>|null  $questions
     *
     * @throws ApiException 422 assignment_empty / answer_key_incomplete
     */
    public static function assertComplete(Assignment $assignment, ?Collection $questions = null): void
    {
        $missing = self::missing($assignment, $questions);
        if ($missing === [0]) {
            throw new ApiException('ยังไม่มีคำถาม เพิ่มคำถามหรือแนบเฉลยก่อน', 'assignment_empty', 422);
        }
        if ($missing !== []) {
            throw new ApiException(
                'ข้อ '.implode(', ', $missing).' ยังไม่มีเฉลยหรือ rubric ที่อนุมัติแล้ว',
                'answer_key_incomplete',
                422,
                ['questions' => array_map(fn (int $p) => "ข้อ {$p} ยังไม่มีเฉลยหรือ rubric ที่อนุมัติแล้ว", $missing)],
            );
        }
    }
}
