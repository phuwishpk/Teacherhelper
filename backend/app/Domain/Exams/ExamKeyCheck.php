<?php

namespace App\Domain\Exams;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * What an exam still lacks (DESIGN §22.3, §22.4). Each action checks only
 * what it uses:
 *
 * - the key ("เฉลยครบ": approving the key, answer sheets, scan-kit of an
 *   `app` exam): every question approved by the teacher and with at least
 *   one accepted answer that fits its section; the prompt is optional;
 * - the booklet (both grading methods): every question approved and with a
 *   prompt (text or image); the key is not looked at.
 *
 * A `ready` app exam whose key stops being complete loses its approval and
 * goes back to `draft` (ready ⇔ key_approved_at, like a freeform assignment).
 */
final class ExamKeyCheck
{
    public const NOT_APPROVED = 'not_approved';

    public const NO_KEY = 'no_key';

    public const NO_PROMPT = 'no_prompt';

    private const REASONS_TH = [
        self::NOT_APPROVED => 'ยังไม่อนุมัติ',
        self::NO_KEY => 'ยังไม่มีเฉลย',
        self::NO_PROMPT => 'ยังไม่มีโจทย์',
    ];

    /**
     * Questions of the exam whose key is not complete, by number.
     *
     * @param  Collection<int, Question>|null  $questions  with `section` loaded; defaults to the exam's questions
     * @return list<array{question_id: int, position: int, reasons: list<string>}>
     */
    public static function keyProblems(Assignment $exam, ?Collection $questions = null): array
    {
        return self::problems($exam, $questions, fn (Question $q) => [
            ...($q->approved_at === null ? [self::NOT_APPROVED] : []),
            ...(self::hasKey($q) ? [] : [self::NO_KEY]),
        ]);
    }

    /**
     * Questions that stop the booklet from printing.
     *
     * @param  Collection<int, Question>|null  $questions
     * @return list<array{question_id: int, position: int, reasons: list<string>}>
     */
    public static function bookletProblems(Assignment $exam, ?Collection $questions = null): array
    {
        return self::problems($exam, $questions, fn (Question $q) => [
            ...($q->approved_at === null ? [self::NOT_APPROVED] : []),
            ...(self::hasPrompt($q) ? [] : [self::NO_PROMPT]),
        ]);
    }

    public static function hasKey(Question $question): bool
    {
        $section = $question->section;

        return $section instanceof ExamSection && ExamAnswerKey::complete($section, $question->answer_key);
    }

    public static function hasPrompt(Question $question): bool
    {
        return trim((string) $question->prompt_text) !== '' || $question->prompt_image_path !== null;
    }

    /**
     * @throws ApiException 422 assignment_empty / answer_key_incomplete (errors.questions)
     */
    public static function assertKeyComplete(Assignment $exam): void
    {
        $questions = self::questions($exam);
        if ($questions->isEmpty()) {
            throw new ApiException('ข้อสอบนี้ยังไม่มีข้อ เพิ่มตอนและข้อก่อน', 'assignment_empty', 422);
        }
        $problems = self::keyProblems($exam, $questions);
        if ($problems !== []) {
            throw new ApiException(
                'ข้อ '.implode(', ', array_column($problems, 'position')).' ยังไม่พร้อม (ต้องอนุมัติข้อและมีเฉลย)',
                'answer_key_incomplete',
                422,
                ['questions' => self::messages($problems)],
            );
        }
    }

    /**
     * "ข้อ 3: ยังไม่อนุมัติ, ยังไม่มีเฉลย" per question.
     *
     * @param  list<array{question_id: int, position: int, reasons: list<string>}>  $problems
     * @return list<string>
     */
    public static function messages(array $problems): array
    {
        return array_map(
            fn (array $p) => "ข้อ {$p['position']}: ".implode(', ', array_map(fn (string $r) => self::REASONS_TH[$r], $p['reasons'])),
            $problems,
        );
    }

    /** A ready `app` exam keeps its approval only while the key is complete. Callers hold the row lock. */
    public static function keepApprovalValid(Assignment $exam): void
    {
        if (! $exam->isExam() || $exam->isManualExam() || ! $exam->isReady()) {
            return;
        }
        $questions = self::questions($exam);
        if ($questions->isNotEmpty() && self::keyProblems($exam, $questions) === []) {
            return;
        }
        $exam->key_approved_at = null;
        $exam->key_approved_by = null;
        $exam->status = Assignment::STATUS_DRAFT;
        $exam->save();
    }

    /**
     * @return Collection<int, Question>
     */
    public static function questions(Assignment $exam): Collection
    {
        return Question::query()->where('assignment_id', $exam->id)->with('section')->orderBy('position')->get();
    }

    /**
     * @param  Collection<int, Question>|null  $questions
     * @param  callable(Question): list<string>  $reasons
     * @return list<array{question_id: int, position: int, reasons: list<string>}>
     */
    private static function problems(Assignment $exam, ?Collection $questions, callable $reasons): array
    {
        $out = [];
        foreach ($questions ?? self::questions($exam) as $question) {
            $found = $reasons($question);
            if ($found !== []) {
                $out[] = ['question_id' => $question->id, 'position' => (int) $question->position, 'reasons' => $found];
            }
        }

        return $out;
    }
}
