<?php

namespace App\Domain\Gemini;

use App\Models\Question;
use App\Models\RubricCriterion;

/**
 * The compact JSON of a question that the one-call-per-page prompts carry
 * (extract_batch, extract_page; DESIGN §21.4, §19.4): number, type, the
 * teacher's question text and key or rubric. Only the teacher's data: never
 * a student's name or id.
 *
 *   mcq        {question_no, type, question, options}
 *   short      {question_no, type, question, accepted, numeric?, spelling_counts}
 *   show_work  {question_no, type, question, accepted_final, reference_steps}
 *   open       {question_no, type, question, criteria: [{criterion_id, description, core}]}
 */
final class QuestionBriefs
{
    /**
     * @param  list<RubricCriterion>  $criteria  open questions, in position order (criterion_id = index + 1)
     * @return array<string, mixed>
     */
    public static function of(Question $question, array $criteria): array
    {
        $key = $question->answer_key ?? [];
        $brief = [
            'question_no' => (int) $question->position,
            'type' => $question->type,
            'question' => trim($question->prompt_text),
        ];

        return $brief + match ($question->type) {
            Question::TYPE_MCQ => ['options' => Question::MCQ_OPTIONS],
            Question::TYPE_SHORT => array_filter([
                'accepted' => array_values(array_map('strval', (array) ($key['accepted'] ?? []))),
                'numeric' => is_array($key['numeric'] ?? null) ? [
                    'value' => (float) $key['numeric']['value'],
                    'tolerance' => (float) ($key['numeric']['abs_tol'] ?? 0),
                ] : null,
                'spelling_counts' => $question->match_mode === 'exact',
            ], fn ($v) => $v !== null),
            Question::TYPE_SHOW_WORK => [
                'accepted_final' => array_values(array_map('strval', (array) ($key['final']['accepted'] ?? []))),
                'reference_steps' => array_values(array_map('strval', (array) ($key['reference_steps'] ?? []))),
            ],
            default => [
                'criteria' => array_map(fn (RubricCriterion $c, int $i) => [
                    'criterion_id' => $i + 1,
                    'description' => trim($c->description),
                    'core' => (bool) $c->is_core,
                ], $criteria, array_keys($criteria)),
            ],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $briefs
     */
    public static function json(array $briefs): string
    {
        return json_encode($briefs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
