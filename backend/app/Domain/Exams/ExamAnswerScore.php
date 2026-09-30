<?php

namespace App\Domain\Exams;

use App\Domain\Grading\Understanding;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionOption;

/**
 * The score of one stored exam answer against the question's CURRENT master
 * key (DESIGN §22.3, §22.11): used by "ครูอ่านรอยฝน" (resolve) and by
 * "ตรวจใหม่ทั้งห้อง" of an exam (ExamRegrade).
 *
 * The answer is `responses.exam_answer`: the teacher's reading
 * (`resolved`) when there is one, otherwise the bubbles as read from the
 * sheet (`selected` in original positions, `value` canonical). exam_answer
 * is rebuilt from exam_sheet_reads whenever the reading changes
 * (ExamSheetGrader), so scoring it is scoring the raw fill: the rule is the
 * one of ExamSheetScorer (exactly one mark in accepted_options, or a value
 * in accepted_values, gets the question's max_points; anything else 0).
 */
final class ExamAnswerScore
{
    /**
     * The answer that counts: resolved when the teacher read the marks.
     *
     * @param  array<string, mixed>|null  $examAnswer
     * @return array{selected: list<int>, value: string|null, resolved: bool}
     */
    public static function effective(?array $examAnswer): array
    {
        $resolved = is_array($examAnswer['resolved'] ?? null) ? $examAnswer['resolved'] : null;
        $source = $resolved ?? $examAnswer ?? [];
        $value = $source['value'] ?? null;

        return [
            'selected' => array_values(array_map('intval', (array) ($source['selected'] ?? []))),
            'value' => is_string($value) && $value !== '' ? $value : null,
            'resolved' => $resolved !== null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $examAnswer
     * @return array{score: float, max: float, understanding: string, blank: bool}
     */
    public static function of(Question $question, ?array $examAnswer): array
    {
        $answer = self::effective($examAnswer);
        $key = is_array($question->answer_key) ? $question->answer_key : [];
        $max = round((float) $question->max_points, 2);
        if ($question->type === Question::TYPE_NUMERIC) {
            $accepted = array_map('strval', (array) ($key['accepted_values'] ?? []));
            $right = $answer['value'] !== null && in_array($answer['value'], $accepted, true);
            // Marks that make no number (invalid_number) are an answer, just a wrong one.
            $blank = $answer['value'] === null
                && ($answer['resolved'] || ! in_array(ExamSheetScorer::INVALID_NUMBER, (array) ($examAnswer['doubts'] ?? []), true));
        } else {
            $accepted = array_map('intval', (array) ($key['accepted_options'] ?? []));
            $right = count($answer['selected']) === 1 && in_array($answer['selected'][0], $accepted, true);
            $blank = $answer['selected'] === [];
        }
        $score = $right ? $max : 0.0;

        return [
            'score' => $score,
            'max' => $max,
            'understanding' => Understanding::fromU($max > 0 ? $score / $max : 0.0),
            'blank' => $blank,
        ];
    }

    /**
     * Labels of a question's bubbles as printed, by displayed position:
     * ก ข ค … (mcq), ถ ผ (true_false), none (numeric). The booklet of every
     * version labels its options in displayed order.
     *
     * @return list<string>
     */
    public static function labels(Question $question): array
    {
        if ($question->type === Question::TYPE_TRUE_FALSE) {
            return ['ถ', 'ผ'];
        }
        if ($question->type !== Question::TYPE_MCQ) {
            return [];
        }
        $count = $question->section instanceof ExamSection ? $question->section->choiceCount() : 0;

        return array_map(fn (int $i) => QuestionOption::label($i), $count > 0 ? range(1, $count) : []);
    }

    /**
     * Original option positions as the labels a version printed for them.
     *
     * @param  list<int>  $original
     * @param  list<int>|null  $order  original position by displayed position, null = not shuffled
     * @return list<string>
     */
    public static function labelsOf(Question $question, array $original, ?array $order): array
    {
        $labels = self::labels($question);

        return array_values(array_map(
            fn (int $displayed) => $labels[$displayed - 1] ?? (string) $displayed,
            ExamVersions::displayed($original, $order),
        ));
    }
}
