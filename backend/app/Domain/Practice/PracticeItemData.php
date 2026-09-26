<?php

namespace App\Domain\Practice;

use App\Domain\Gemini\PromptText;
use App\Domain\Grading\AnswerMatcher;
use App\Models\PracticeItem;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises a complete practice item payload (DESIGN §8.5):
 *
 *   numeric  answer_key {"accepted": [..], "numeric": {"value", "abs_tol"}}  (accepted defaults to the value)
 *   short    answer_key {"accepted": [..]}
 *   mcq      options [{key, text}] (or plain strings, keyed A, B, …), answer_key {"correct": key}
 *
 * For PATCH the caller merges the current item with the changes first, so
 * the whole item is checked every time. Unknown keys are dropped.
 */
final class PracticeItemData
{
    public const MAX_PROMPT = 2000;

    public const MAX_EXPLANATION = 2000;

    public const MAX_ACCEPTED = 10;

    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 6;

    /** Fields a client may send. */
    public const FIELDS = ['answer_type', 'prompt_text', 'options', 'answer_key', 'explanation', 'status'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{answer_type: string, prompt_text: string, options: list<array{key: string, text: string}>|null, answer_key: array<string, mixed>, explanation: string, status: string}
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $input = array_intersect_key($input, array_flip(self::FIELDS));
        $type = is_string($input['answer_type'] ?? null) ? $input['answer_type'] : null;
        if (isset($input['options']) && is_array($input['options'])) {
            $input['options'] = self::normaliseOptions($input['options']);
        }
        if (isset($input['answer_key']['correct']) && is_string($input['answer_key']['correct'])) {
            $input['answer_key']['correct'] = mb_strtoupper(trim($input['answer_key']['correct']), 'UTF-8');
        }
        $optionKeys = array_map(fn (array $o) => $o['key'], is_array($input['options'] ?? null) ? $input['options'] : []);

        $rules = [
            'answer_type' => ['required', 'string', Rule::in(PracticeItem::TYPES)],
            'prompt_text' => ['required', 'string', 'max:'.self::MAX_PROMPT],
            'explanation' => ['required', 'string', 'max:'.self::MAX_EXPLANATION],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(PracticeItem::STATUSES)],
            'answer_key' => ['required', 'array'],
        ];
        if ($type === PracticeItem::TYPE_MCQ) {
            $rules += [
                'options' => ['required', 'array', 'min:'.self::MIN_OPTIONS, 'max:'.self::MAX_OPTIONS],
                'options.*.key' => ['required', 'string', 'max:3', 'distinct'],
                'options.*.text' => ['required', 'string', 'max:255', 'distinct'],
                'answer_key.correct' => ['required', 'string', Rule::in($optionKeys)],
            ];
        } elseif ($type !== null) {
            $numericRequired = $type === PracticeItem::TYPE_NUMERIC;
            $rules += [
                'options' => ['prohibited'],
                'answer_key.accepted' => [$numericRequired ? 'sometimes' : 'required', 'array', 'max:'.self::MAX_ACCEPTED],
                'answer_key.accepted.*' => ['required', 'string', 'max:255'],
                'answer_key.numeric' => [$numericRequired ? 'required' : 'sometimes', 'nullable', 'array'],
                'answer_key.numeric.value' => ['required_with:answer_key.numeric', 'numeric'],
                'answer_key.numeric.abs_tol' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            ];
            if (! $numericRequired) {
                $rules['answer_key.accepted'][] = 'min:1';
            }
        }

        $validated = Validator::make($input, $rules, self::messages())->validate();

        return [
            'answer_type' => $type,
            'prompt_text' => trim((string) $validated['prompt_text']),
            'options' => $type === PracticeItem::TYPE_MCQ ? array_values($validated['options']) : null,
            'answer_key' => self::normaliseKey($type, $validated['answer_key']),
            'explanation' => trim((string) $validated['explanation']),
            'status' => $validated['status'] ?? PracticeItem::STATUS_DRAFT,
        ];
    }

    /**
     * [{key, text}], ["text", ..] or {"A": "text"} -> [{key, text}] with keys upper-cased.
     *
     * @param  array<int|string, mixed>  $options
     * @return list<array{key: string, text: string}>
     */
    public static function normaliseOptions(array $options): array
    {
        $out = [];
        $i = 0;
        foreach ($options as $key => $option) {
            if (is_array($option)) {
                $optionKey = (string) ($option['key'] ?? $option['label'] ?? self::letter($i));
                $text = (string) ($option['text'] ?? $option['value'] ?? '');
            } elseif (is_string($key)) {
                $optionKey = $key;
                $text = (string) $option;
            } else {
                $optionKey = self::letter($i);
                $text = is_scalar($option) ? (string) $option : '';
            }
            $out[] = ['key' => mb_strtoupper(trim($optionKey), 'UTF-8'), 'text' => trim($text)];
            $i++;
        }

        return $out;
    }

    public static function letter(int $index): string
    {
        return $index < 26 ? chr(ord('A') + $index) : (string) ($index + 1);
    }

    /**
     * @param  array<string, mixed>  $key
     * @return array<string, mixed>
     */
    private static function normaliseKey(?string $type, array $key): array
    {
        if ($type === PracticeItem::TYPE_MCQ) {
            return ['correct' => $key['correct']];
        }
        $accepted = array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) ($key['accepted'] ?? [])), fn (string $v) => $v !== ''));
        $out = ['accepted' => $accepted];
        if (isset($key['numeric']) && is_array($key['numeric'])) {
            $value = (float) $key['numeric']['value'];
            $out['numeric'] = ['value' => $value, 'abs_tol' => (float) ($key['numeric']['abs_tol'] ?? 0)];
            if ($out['accepted'] === []) {
                $out['accepted'] = [PromptText::number($value)];
            }
        } elseif ($type === PracticeItem::TYPE_NUMERIC) {
            $parsed = $accepted === [] ? null : AnswerMatcher::parseNumber($accepted[0]);
            if ($parsed !== null) {
                $out['numeric'] = ['value' => $parsed, 'abs_tol' => 0.0];
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'answer_type.required' => 'กรุณาเลือกประเภทคำตอบ',
            'answer_type.in' => 'ประเภทคำตอบต้องเป็น numeric, short หรือ mcq',
            'prompt_text.required' => 'กรุณากรอกโจทย์',
            'prompt_text.max' => 'โจทย์ยาวเกิน '.self::MAX_PROMPT.' ตัวอักษร',
            'explanation.required' => 'กรุณากรอกคำอธิบายเฉลย',
            'explanation.max' => 'คำอธิบายยาวเกิน '.self::MAX_EXPLANATION.' ตัวอักษร',
            'status.in' => 'สถานะต้องเป็น draft, approved หรือ retired',
            'answer_key.required' => 'กรุณาใส่เฉลย',
            'options.required' => 'ข้อปรนัยต้องมีตัวเลือก',
            'options.min' => 'ต้องมีตัวเลือกอย่างน้อย '.self::MIN_OPTIONS.' ข้อ',
            'options.max' => 'ตัวเลือกมีได้ไม่เกิน '.self::MAX_OPTIONS.' ข้อ',
            'options.prohibited' => 'ตัวเลือกใช้กับข้อปรนัยเท่านั้น',
            'options.*.key.required' => 'ตัวเลือกต้องมีตัวอักษรกำกับ',
            'options.*.key.distinct' => 'ตัวอักษรกำกับตัวเลือกซ้ำกัน',
            'options.*.text.required' => 'ตัวเลือกต้องมีข้อความ',
            'options.*.text.distinct' => 'ตัวเลือกซ้ำกัน',
            'answer_key.correct.required' => 'กรุณาเลือกตัวเลือกที่ถูก',
            'answer_key.correct.in' => 'ตัวเลือกที่ถูกต้องตรงกับตัวอักษรของตัวเลือก',
            'answer_key.accepted.required' => 'กรุณาใส่คำตอบที่ยอมรับอย่างน้อย 1 คำตอบ',
            'answer_key.accepted.min' => 'กรุณาใส่คำตอบที่ยอมรับอย่างน้อย 1 คำตอบ',
            'answer_key.accepted.max' => 'คำตอบที่ยอมรับมีได้ไม่เกิน '.self::MAX_ACCEPTED.' คำตอบ',
            'answer_key.accepted.*.required' => 'คำตอบที่ยอมรับต้องไม่ว่าง',
            'answer_key.numeric.required' => 'ข้อตัวเลขต้องมีค่าเฉลย (numeric.value)',
            'answer_key.numeric.value.required_with' => 'กรุณาใส่ค่าเฉลยตัวเลข',
            'answer_key.numeric.value.numeric' => 'ค่าเฉลยต้องเป็นตัวเลข',
            'answer_key.numeric.abs_tol.min' => 'ค่าคลาดเคลื่อนต้องไม่ติดลบ',
        ];
    }
}
