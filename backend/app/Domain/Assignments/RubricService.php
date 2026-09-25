<?php

namespace App\Domain\Assignments;

use App\Domain\Gemini\RubricDraft;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\RubricCriterion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Rubrics of show_work and open questions (DESIGN §2.2, §9.3, §10.4).
 *
 * - approve(): PUT /questions/{id}/rubric. open needs criteria; show_work may
 *   send criteria too, and its reference steps live in answer_key. Criteria
 *   must have exactly one is_core and points that sum to max_points. The
 *   rubric becomes `approved`.
 * - applyDraft(): DraftRubricJob stores Gemini's draft as `draft` with
 *   source = ai, for the teacher to edit and approve.
 */
class RubricService
{
    public const MAX_CRITERIA = 10;

    /**
     * @param  array<string, mixed>  $input  {criteria: [{description, points, is_core}], reference_steps?: [..]}
     */
    public function approve(Question $question, array $input): Question
    {
        if (! $question->needsRubric()) {
            throw new ApiException('ข้อประเภทนี้ไม่ใช้ rubric', 'rubric_not_needed', 422);
        }

        return AssignmentLocked::run($question->assignment_id, function (Assignment $assignment) use ($question, $input) {
            $question = Question::query()->with('rubricCriteria')->findOrFail($question->id);
            $validated = $this->validate($question, $input);

            $aiDescriptions = $question->rubricCriteria
                ->where('source', RubricCriterion::SOURCE_AI)
                ->pluck('description')
                ->all();

            $this->replaceCriteria($question, array_map(fn (array $c) => [
                ...$c,
                // An AI criterion the teacher kept word for word stays `ai`.
                'source' => in_array($c['description'], $aiDescriptions, true)
                    ? RubricCriterion::SOURCE_AI
                    : RubricCriterion::SOURCE_TEACHER,
            ], $validated['criteria']));

            if ($question->type === Question::TYPE_SHOW_WORK && $validated['reference_steps'] !== null) {
                $key = $question->answer_key ?? [];
                $key['reference_steps'] = $validated['reference_steps'];
                $question->answer_key = $key;
            }
            $question->rubric_status = Question::RUBRIC_APPROVED;
            $question->save();

            return $question;
        });
    }

    /** Stores an AI draft; an approved rubric goes back to `draft` (the teacher asked for a new one). */
    public function applyDraft(Question $question, RubricDraft $draft): Question
    {
        return AssignmentLocked::run($question->assignment_id, function (Assignment $assignment) use ($question, $draft) {
            $question = Question::query()->findOrFail($question->id);
            if (! $question->needsRubric() || $assignment->isClosed()) {
                return $question; // changed or closed while the job waited
            }

            if ($draft->criteria !== [] || $question->type === Question::TYPE_OPEN) {
                $this->replaceCriteria($question, array_map(fn (array $c) => [
                    ...$c,
                    'source' => RubricCriterion::SOURCE_AI,
                ], $draft->criteria));
            }
            if ($question->type === Question::TYPE_SHOW_WORK && $draft->referenceSteps !== []) {
                $key = $question->answer_key ?? [];
                $key['reference_steps'] = $draft->referenceSteps;
                $question->answer_key = $key;
            }
            $question->rubric_status = Question::RUBRIC_DRAFT;
            $question->save();
            $assignment->backToDraft();

            return $question;
        }, allowClosed: true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{criteria: list<array{description: string, points: float, is_core: bool}>, reference_steps: list<string>|null}
     *
     * @throws ValidationException
     */
    private function validate(Question $question, array $input): array
    {
        $isOpen = $question->type === Question::TYPE_OPEN;

        $validator = Validator::make($input, [
            'criteria' => [$isOpen ? 'required' : 'nullable', 'array', 'max:'.self::MAX_CRITERIA],
            'criteria.*' => ['array'],
            'criteria.*.description' => ['required', 'string', 'max:1000'],
            'criteria.*.points' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'criteria.*.is_core' => ['sometimes', 'boolean'],
            'reference_steps' => $isOpen
                ? ['prohibited']
                : ['sometimes', 'nullable', 'array', 'max:'.QuestionData::MAX_REFERENCE_STEPS],
            'reference_steps.*' => ['nullable', 'string', 'max:500'],
        ], [
            'criteria.required' => 'ข้ออัตนัยต้องมีเกณฑ์การให้คะแนนอย่างน้อย 1 ข้อ',
            'criteria.array' => 'รูปแบบเกณฑ์ไม่ถูกต้อง',
            'criteria.max' => 'เกณฑ์มีได้ไม่เกิน '.self::MAX_CRITERIA.' ข้อ',
            'criteria.*.description.required' => 'เกณฑ์ทุกข้อต้องมีคำอธิบาย',
            'criteria.*.description.max' => 'คำอธิบายเกณฑ์ยาวเกิน 1000 ตัวอักษร',
            'criteria.*.points.required' => 'เกณฑ์ทุกข้อต้องมีคะแนน',
            'criteria.*.points.numeric' => 'คะแนนของเกณฑ์ต้องเป็นตัวเลข',
            'criteria.*.points.min' => 'คะแนนของเกณฑ์ต้องไม่ติดลบ',
            'criteria.*.points.max' => 'คะแนนของเกณฑ์ต้องไม่เกิน 100',
            'criteria.*.points.decimal' => 'คะแนนของเกณฑ์มีทศนิยมได้ไม่เกิน 2 ตำแหน่ง',
            'reference_steps.prohibited' => 'ข้ออัตนัยไม่มีขั้นตอนอ้างอิง',
            'reference_steps.max' => 'ขั้นตอนอ้างอิงมีได้ไม่เกิน '.QuestionData::MAX_REFERENCE_STEPS.' ขั้น',
            'reference_steps.*.max' => 'ขั้นตอนอ้างอิงแต่ละขั้นยาวเกิน 500 ตัวอักษร',
        ]);

        $validator->after(function ($validator) use ($question) {
            $criteria = $validator->getData()['criteria'] ?? null;
            if (! is_array($criteria) || $criteria === [] || $validator->errors()->isNotEmpty()) {
                return;
            }
            $core = count(array_filter($criteria, fn ($c) => filter_var($c['is_core'] ?? false, FILTER_VALIDATE_BOOLEAN)));
            if ($core !== 1) {
                $validator->errors()->add('criteria', 'ต้องเลือกเกณฑ์หลัก (is_core) 1 ข้อพอดี');
            }
            $sum = round(array_sum(array_map(fn ($c) => (float) $c['points'], $criteria)), 2);
            if (abs($sum - $question->max_points) > 0.001) {
                $validator->errors()->add(
                    'criteria',
                    'คะแนนรวมของเกณฑ์ ('.self::fmt($sum).') ต้องเท่ากับคะแนนเต็มของข้อ ('.self::fmt($question->max_points).')',
                );
            }
        });

        $validated = $validator->validate();

        return [
            'criteria' => array_values(array_map(fn (array $c) => [
                'description' => trim((string) $c['description']),
                'points' => round((float) $c['points'], 2),
                'is_core' => filter_var($c['is_core'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ], $validated['criteria'] ?? [])),
            'reference_steps' => array_key_exists('reference_steps', $validated) && $validated['reference_steps'] !== null
                ? array_values(array_filter(array_map(fn ($s) => trim((string) $s), $validated['reference_steps']), fn (string $s) => $s !== ''))
                : null,
        ];
    }

    /**
     * @param  list<array{description: string, points: float, is_core: bool, source: string}>  $criteria
     */
    private function replaceCriteria(Question $question, array $criteria): void
    {
        $question->rubricCriteria()->delete();
        foreach (array_values($criteria) as $i => $criterion) {
            $question->rubricCriteria()->create([
                'position' => $i + 1,
                'description' => $criterion['description'],
                'points' => $criterion['points'],
                'is_core' => $criterion['is_core'],
                'source' => $criterion['source'],
            ]);
        }
        $question->unsetRelation('rubricCriteria');
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
