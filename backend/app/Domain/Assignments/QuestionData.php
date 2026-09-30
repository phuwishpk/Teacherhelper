<?php

namespace App\Domain\Assignments;

use App\Models\Assignment;
use App\Models\Question;
use App\Models\Skill;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises a complete question payload (DESIGN §8.3),
 * including the answer_key shape of each type:
 *
 *   mcq       {"correct": "A"|"B"|"C"|"D"}
 *   short     {"accepted": [..], "numeric"?: {"value", "abs_tol"}}
 *   show_work {"final": {"accepted": [..], "numeric"?: {..}}, "reference_steps": [..]}
 *   open      null (graded with rubric_criteria)
 *
 * Unknown keys are dropped, so answer_key only ever holds the documented shape.
 *
 * In a freeform assignment (DESIGN §19.5) answer_key may be left out (null)
 * until it is typed, read from a document or drafted by AI; approving the
 * key checks it (KeyCompleteness). model_answer is kept for open questions
 * only (the teacher's model answer, a reference for the rubric draft and
 * for extraction).
 */
final class QuestionData
{
    public const MAX_QUESTIONS = 100;

    public const MAX_PROMPT = 2000;

    public const MAX_ANSWER_LINES = 15;

    public const MAX_SKILLS = 10;

    public const MAX_ACCEPTED = 20;

    public const MAX_REFERENCE_STEPS = 10;

    public const MAX_MODEL_ANSWER = 4000;

    /** Fields a client may send for a question. */
    public const FIELDS = [
        'position', 'type', 'prompt_text', 'max_points', 'answer_lines',
        'is_numeric', 'match_mode', 'answer_key', 'skill_ids', 'model_answer',
    ];

    /**
     * @param  array<string, mixed>  $input  a full payload (for PATCH: the current question merged with the changes)
     * @return array{attributes: array<string, mixed>, position: int|null, skill_ids: list<int>|null}
     *
     * @throws ValidationException
     */
    public static function validate(array $input, Assignment $assignment): array
    {
        $input = array_intersect_key($input, array_flip(self::FIELDS));
        if (isset($input['answer_key']['correct']) && is_string($input['answer_key']['correct'])) {
            $input['answer_key']['correct'] = strtoupper(trim($input['answer_key']['correct']));
        }

        $type = is_string($input['type'] ?? null) ? $input['type'] : null;
        $needsLines = $type !== null && Question::typeNeedsRubric($type);
        $keyOptional = $assignment->isFreeform() && ($input['answer_key'] ?? null) === null;

        $rules = [
            'position' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_QUESTIONS],
            'type' => ['required', 'string', Rule::in(Question::TYPES)],
            'prompt_text' => ['required', 'string', 'max:'.self::MAX_PROMPT],
            'max_points' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'answer_lines' => $needsLines
                ? ['required', 'integer', 'min:1', 'max:'.self::MAX_ANSWER_LINES]
                : ['nullable'],
            'is_numeric' => ['sometimes', 'nullable', 'boolean', self::numericAllowed($type)],
            'match_mode' => ['sometimes', 'nullable', 'string', Rule::in(Question::MATCH_MODES)],
            'skill_ids' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_SKILLS],
            'skill_ids.*' => [
                'integer',
                'distinct',
                // Questions use indicators and sub-indicators only (DESIGN §20.2).
                Rule::exists('skills', 'id')
                    ->where('subject_id', $assignment->subject_id)
                    ->whereIn('level', Skill::ASSESSABLE_LEVELS)
                    ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $assignment->school_id)),
            ],
            'model_answer' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_MODEL_ANSWER],
            ...($keyOptional ? ['answer_key' => ['nullable']] : self::answerKeyRules($type)),
        ];

        $validated = Validator::make($input, $rules, self::messages())->validate();

        $isNumeric = in_array($type, [Question::TYPE_SHORT, Question::TYPE_SHOW_WORK], true)
            && filter_var($validated['is_numeric'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'attributes' => [
                'type' => $type,
                'prompt_text' => trim((string) $validated['prompt_text']),
                'max_points' => round((float) $validated['max_points'], 2),
                'answer_lines' => $needsLines ? (int) $validated['answer_lines'] : null,
                'is_numeric' => $isNumeric,
                'match_mode' => $validated['match_mode'] ?? 'flexible',
                'answer_key' => $keyOptional ? null : self::normaliseKey($type, $validated['answer_key'] ?? null),
                'model_answer' => $type === Question::TYPE_OPEN ? self::modelAnswer($validated['model_answer'] ?? null) : null,
            ],
            'position' => isset($validated['position']) ? (int) $validated['position'] : null,
            'skill_ids' => array_key_exists('skill_ids', $validated)
                ? array_values(array_map('intval', $validated['skill_ids'] ?? []))
                : null,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private static function answerKeyRules(?string $type): array
    {
        $accepted = fn (string $prefix): array => [
            "{$prefix}accepted" => ['required', 'array', 'min:1', 'max:'.self::MAX_ACCEPTED],
            "{$prefix}accepted.*" => ['required', 'string', 'max:255'],
            "{$prefix}numeric" => ['sometimes', 'nullable', 'array'],
            "{$prefix}numeric.value" => ["required_with:{$prefix}numeric", 'numeric'],
            "{$prefix}numeric.abs_tol" => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];

        return match ($type) {
            Question::TYPE_MCQ => [
                'answer_key' => ['required', 'array'],
                'answer_key.correct' => ['required', 'string', Rule::in(Question::MCQ_OPTIONS)],
            ],
            Question::TYPE_SHORT => [
                'answer_key' => ['required', 'array'],
                ...$accepted('answer_key.'),
            ],
            Question::TYPE_SHOW_WORK => [
                'answer_key' => ['required', 'array'],
                'answer_key.final' => ['required', 'array'],
                ...$accepted('answer_key.final.'),
                'answer_key.reference_steps' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_REFERENCE_STEPS],
                'answer_key.reference_steps.*' => ['nullable', 'string', 'max:500'],
            ],
            Question::TYPE_OPEN => [
                'answer_key' => ['prohibited'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normaliseKey(?string $type, mixed $key): ?array
    {
        return match ($type) {
            Question::TYPE_MCQ => ['correct' => $key['correct']],
            Question::TYPE_SHORT => self::acceptedKey($key),
            Question::TYPE_SHOW_WORK => [
                'final' => self::acceptedKey($key['final']),
                'reference_steps' => self::strings($key['reference_steps'] ?? []),
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $key
     * @return array{accepted: list<string>, numeric?: array{value: float, abs_tol: float}}
     */
    private static function acceptedKey(array $key): array
    {
        $out = ['accepted' => self::strings($key['accepted'])];
        if (isset($key['numeric']) && is_array($key['numeric'])) {
            $out['numeric'] = [
                'value' => (float) $key['numeric']['value'],
                'abs_tol' => (float) ($key['numeric']['abs_tol'] ?? 0),
            ];
        }

        return $out;
    }

    private static function modelAnswer(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $values), fn (string $v) => $v !== ''));
    }

    private static function numericAllowed(?string $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type) {
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && in_array($type, [Question::TYPE_MCQ, Question::TYPE_OPEN], true)) {
                $fail('ข้อปรนัยและข้ออัตนัยไม่มีกรอบคำตอบตัวเลขให้ CNN อ่าน');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'position.integer' => 'ลำดับข้อต้องเป็นตัวเลข',
            'position.min' => 'ลำดับข้อต้องเริ่มที่ 1',
            'position.max' => 'การบ้านหนึ่งชุดมีได้ไม่เกิน '.self::MAX_QUESTIONS.' ข้อ',
            'type.required' => 'กรุณาเลือกประเภทคำถาม',
            'type.in' => 'ประเภทคำถามต้องเป็น ปรนัย ตอบสั้น แสดงวิธีทำ หรืออัตนัย',
            'prompt_text.required' => 'กรุณากรอกโจทย์',
            'prompt_text.max' => 'โจทย์ยาวเกิน '.self::MAX_PROMPT.' ตัวอักษร',
            'max_points.required' => 'กรุณากรอกคะแนนเต็ม',
            'max_points.numeric' => 'คะแนนเต็มต้องเป็นตัวเลข',
            'max_points.gt' => 'คะแนนเต็มต้องมากกว่า 0',
            'max_points.max' => 'คะแนนเต็มต้องไม่เกิน 100',
            'max_points.decimal' => 'คะแนนเต็มมีทศนิยมได้ไม่เกิน 2 ตำแหน่ง',
            'answer_lines.required' => 'กรุณากำหนดจำนวนบรรทัดสำหรับเขียนคำตอบ',
            'answer_lines.integer' => 'จำนวนบรรทัดต้องเป็นตัวเลข',
            'answer_lines.min' => 'ต้องมีอย่างน้อย 1 บรรทัด',
            'answer_lines.max' => 'กำหนดได้ไม่เกิน '.self::MAX_ANSWER_LINES.' บรรทัด',
            'is_numeric.boolean' => 'ค่า is_numeric ต้องเป็นจริงหรือเท็จ',
            'match_mode.in' => 'วิธีเทียบคำตอบต้องเป็น flexible หรือ exact',
            'skill_ids.array' => 'รายการทักษะไม่ถูกต้อง',
            'skill_ids.max' => 'เลือกทักษะได้ไม่เกิน '.self::MAX_SKILLS.' ทักษะต่อข้อ',
            'skill_ids.*.integer' => 'รหัสทักษะไม่ถูกต้อง',
            'skill_ids.*.distinct' => 'เลือกทักษะซ้ำกัน',
            'skill_ids.*.exists' => 'ไม่พบตัวชี้วัดนี้ในวิชาของการบ้าน (เลือกได้เฉพาะตัวชี้วัดหรือทักษะย่อย)',
            'answer_key.required' => 'กรุณากรอกเฉลย',
            'answer_key.array' => 'รูปแบบเฉลยไม่ถูกต้อง',
            'answer_key.prohibited' => 'ข้ออัตนัยไม่ใช้เฉลย ให้ใช้เกณฑ์การให้คะแนน (rubric) แทน',
            'answer_key.correct.required' => 'กรุณาเลือกตัวเลือกที่ถูก',
            'answer_key.correct.in' => 'ตัวเลือกที่ถูกต้องเป็น A, B, C หรือ D',
            'answer_key.accepted.required' => 'กรุณากรอกคำตอบที่ยอมรับได้อย่างน้อย 1 แบบ',
            'answer_key.accepted.min' => 'กรุณากรอกคำตอบที่ยอมรับได้อย่างน้อย 1 แบบ',
            'answer_key.accepted.max' => 'คำตอบที่ยอมรับได้มีได้ไม่เกิน '.self::MAX_ACCEPTED.' แบบ',
            'answer_key.accepted.*.required' => 'คำตอบที่ยอมรับได้ต้องไม่ว่าง',
            'answer_key.accepted.*.max' => 'คำตอบที่ยอมรับได้ยาวเกิน 255 ตัวอักษร',
            'answer_key.numeric.value.required_with' => 'กรุณากรอกค่าตัวเลขของเฉลย',
            'answer_key.numeric.value.numeric' => 'ค่าตัวเลขของเฉลยต้องเป็นตัวเลข',
            'answer_key.numeric.abs_tol.numeric' => 'ค่าความคลาดเคลื่อนต้องเป็นตัวเลข',
            'answer_key.numeric.abs_tol.min' => 'ค่าความคลาดเคลื่อนต้องไม่ติดลบ',
            'answer_key.final.required' => 'กรุณากรอกคำตอบสุดท้าย',
            'answer_key.final.array' => 'รูปแบบคำตอบสุดท้ายไม่ถูกต้อง',
            'answer_key.final.accepted.required' => 'กรุณากรอกคำตอบสุดท้ายที่ยอมรับได้อย่างน้อย 1 แบบ',
            'answer_key.final.accepted.min' => 'กรุณากรอกคำตอบสุดท้ายที่ยอมรับได้อย่างน้อย 1 แบบ',
            'answer_key.final.accepted.max' => 'คำตอบสุดท้ายที่ยอมรับได้มีได้ไม่เกิน '.self::MAX_ACCEPTED.' แบบ',
            'answer_key.final.accepted.*.required' => 'คำตอบสุดท้ายต้องไม่ว่าง',
            'answer_key.final.accepted.*.max' => 'คำตอบสุดท้ายยาวเกิน 255 ตัวอักษร',
            'answer_key.final.numeric.value.required_with' => 'กรุณากรอกค่าตัวเลขของคำตอบสุดท้าย',
            'answer_key.final.numeric.value.numeric' => 'ค่าตัวเลขของคำตอบสุดท้ายต้องเป็นตัวเลข',
            'answer_key.final.numeric.abs_tol.numeric' => 'ค่าความคลาดเคลื่อนต้องเป็นตัวเลข',
            'answer_key.final.numeric.abs_tol.min' => 'ค่าความคลาดเคลื่อนต้องไม่ติดลบ',
            'answer_key.reference_steps.array' => 'ขั้นตอนอ้างอิงต้องเป็นรายการ',
            'answer_key.reference_steps.max' => 'ขั้นตอนอ้างอิงมีได้ไม่เกิน '.self::MAX_REFERENCE_STEPS.' ขั้น',
            'answer_key.reference_steps.*.string' => 'ขั้นตอนอ้างอิงต้องเป็นข้อความ',
            'answer_key.reference_steps.*.max' => 'ขั้นตอนอ้างอิงแต่ละขั้นยาวเกิน 500 ตัวอักษร',
            'model_answer.string' => 'คำตอบตัวอย่างต้องเป็นข้อความ',
            'model_answer.max' => 'คำตอบตัวอย่างยาวเกิน '.self::MAX_MODEL_ANSWER.' ตัวอักษร',
        ];
    }
}
