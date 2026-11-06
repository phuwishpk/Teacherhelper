<?php

namespace App\Domain\Exams;

use App\Models\ExamSection;
use App\Models\QuestionOption;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises the master answer key of one exam question
 * (DESIGN §22.3), in the original order of the options:
 *
 *   mcq         {"accepted_options": [2, 4]}   1..option_count, several allowed
 *   true_false  {"accepted_options": [1]}      1 = ถูก, 2 = ผิด
 *   numeric     {"accepted_values": ["0.5"]}   canonical (NumericAnswer), must fit the section
 *
 * null (or an empty list) clears the key. Errors name the exact field, e.g.
 * `answers.3.accepted_values.0` for PUT /exams/{id}/answer-key.
 */
final class ExamAnswerKey
{
    public const MAX_VALUES = 10;

    /**
     * @param  mixed  $key  {accepted_options?: list<int>, accepted_values?: list<string>} or null
     * @param  string  $prefix  field prefix of the errors, e.g. "answer_key." or "answers.3."
     * @return array{accepted_options: list<int>}|array{accepted_values: list<string>}|null
     *
     * @throws ValidationException
     */
    public static function normalise(ExamSection $section, mixed $key, string $prefix): ?array
    {
        if ($key === null) {
            return null;
        }
        if (! is_array($key)) {
            throw ValidationException::withMessages([rtrim($prefix, '.') => 'รูปแบบเฉลยไม่ถูกต้อง']);
        }

        return $section->type === ExamSection::TYPE_NUMERIC
            ? self::values($section, $key, $prefix)
            : self::options($section, $key, $prefix);
    }

    /**
     * @param  array<string, mixed>  $key
     * @return array{accepted_options: list<int>}|null
     */
    private static function options(ExamSection $section, array $key, string $prefix): ?array
    {
        if (array_key_exists('accepted_values', $key) && $key['accepted_values'] !== null && $key['accepted_values'] !== []) {
            throw ValidationException::withMessages([$prefix.'accepted_values' => 'ข้อนี้ต้องเลือกตัวเลือก ไม่ใช่ค่าตัวเลข']);
        }
        $options = $key['accepted_options'] ?? null;
        if ($options === null || $options === []) {
            return null;
        }
        if (! is_array($options) || ! array_is_list($options)) {
            throw ValidationException::withMessages([$prefix.'accepted_options' => 'ตัวเลือกที่ถูกต้องต้องเป็นรายการ']);
        }

        $count = $section->choiceCount();
        $labels = $section->type === ExamSection::TYPE_TRUE_FALSE
            ? 'ถูก (1) หรือ ผิด (2)'
            : implode(' ', array_map(fn (int $p) => QuestionOption::label($p), range(1, max(1, $count))));
        $out = [];
        foreach ($options as $i => $option) {
            $value = filter_var($option, FILTER_VALIDATE_INT);
            if ($value === false || $value < 1 || $value > $count) {
                throw ValidationException::withMessages([$prefix."accepted_options.{$i}" => "ตัวเลือกที่ถูกต้องต้องเป็น {$labels}"]);
            }
            if (in_array($value, $out, true)) {
                throw ValidationException::withMessages([$prefix."accepted_options.{$i}" => 'เลือกตัวเลือกซ้ำกัน']);
            }
            $out[] = $value;
        }
        if ($section->type === ExamSection::TYPE_TRUE_FALSE && count($out) > 1) {
            throw ValidationException::withMessages([$prefix.'accepted_options' => 'ข้อถูก/ผิดมีคำตอบที่ถูกได้ข้อเดียว']);
        }
        sort($out);

        return ['accepted_options' => $out];
    }

    /**
     * @param  array<string, mixed>  $key
     * @return array{accepted_values: list<string>}|null
     */
    private static function values(ExamSection $section, array $key, string $prefix): ?array
    {
        if (array_key_exists('accepted_options', $key) && $key['accepted_options'] !== null && $key['accepted_options'] !== []) {
            throw ValidationException::withMessages([$prefix.'accepted_options' => 'ข้อเติมตัวเลขต้องใส่ค่าตัวเลข ไม่ใช่ตัวเลือก']);
        }
        $values = $key['accepted_values'] ?? null;
        if ($values === null || $values === []) {
            return null;
        }
        if (! is_array($values) || ! array_is_list($values)) {
            throw ValidationException::withMessages([$prefix.'accepted_values' => 'ค่าที่ยอมรับต้องเป็นรายการ']);
        }
        if (count($values) > self::MAX_VALUES) {
            throw ValidationException::withMessages([$prefix.'accepted_values' => 'ค่าที่ยอมรับมีได้ไม่เกิน '.self::MAX_VALUES.' ค่า']);
        }

        $out = [];
        foreach ($values as $i => $value) {
            $canonical = is_string($value) || is_int($value) || is_float($value) ? NumericAnswer::canonical((string) $value) : null;
            if ($canonical === null) {
                throw ValidationException::withMessages([$prefix."accepted_values.{$i}" => 'ค่าที่ยอมรับต้องเป็นตัวเลข เช่น 12, -3 หรือ 0.5']);
            }
            if (! NumericAnswer::fits($canonical, $section)) {
                throw ValidationException::withMessages([$prefix."accepted_values.{$i}" => self::fitMessage($canonical, $section)]);
            }
            if (! in_array($canonical, $out, true)) {
                $out[] = $canonical;
            }
        }

        return ['accepted_values' => $out];
    }

    /** Why a value does not fit the digit block of the section. */
    public static function fitMessage(string $canonical, ExamSection $section): string
    {
        if (str_starts_with($canonical, '-') && ! $section->numeric_allow_negative) {
            return "ค่า {$canonical} ติดลบ แต่ตอนนี้ไม่มีช่องเครื่องหมายลบ";
        }
        if (str_contains($canonical, '.') && ! $section->numeric_allow_decimal) {
            return "ค่า {$canonical} มีทศนิยม แต่ตอนนี้ไม่มีช่องจุดทศนิยม";
        }

        return "ค่า {$canonical} ยาวเกินช่องตัวเลข {$section->numeric_digits} หลักของตอนนี้";
    }

    /** Whether the stored key has at least one accepted answer that still fits the section. */
    public static function complete(ExamSection $section, mixed $key): bool
    {
        if (! is_array($key)) {
            return false;
        }
        if ($section->type === ExamSection::TYPE_NUMERIC) {
            $values = $key['accepted_values'] ?? [];

            return is_array($values) && $values !== []
                && array_filter($values, fn ($v) => ! is_string($v) || ! NumericAnswer::fits($v, $section)) === [];
        }
        $options = $key['accepted_options'] ?? [];
        $count = $section->choiceCount();

        return is_array($options) && $options !== []
            && array_filter($options, fn ($o) => ! is_int($o) || $o < 1 || $o > $count) === [];
    }
}
