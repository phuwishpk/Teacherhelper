<?php

namespace App\Domain\Students;

use App\Exceptions\ApiException;
use App\Models\User;

/**
 * The school student ID (เลขประจำตัวนักเรียน, DESIGN §24.4): optional,
 * normalised before it is stored and before it is searched (spaces removed,
 * Thai digits to Arabic, letters upper case), then `[0-9A-Z-]{1,20}`, and
 * unique within a school.
 */
final class StudentCode
{
    public const MAX_LENGTH = 20;

    /** The normalised code, or null for an empty value. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtr($value, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
        $value = preg_replace('/\s+/u', '', $value) ?? '';
        $value = strtoupper($value);

        return $value === '' ? null : $value;
    }

    public static function isValid(string $normalized): bool
    {
        return preg_match('/\A[0-9A-Z-]{1,'.self::MAX_LENGTH.'}\z/', $normalized) === 1;
    }

    /**
     * Normalises and checks one input value.
     *
     * @throws ApiException 422 validation_failed at $field
     */
    public static function parse(mixed $value, string $field): ?string
    {
        if ($value !== null && ! is_string($value) && ! is_int($value)) {
            throw self::invalid($field);
        }
        $code = self::normalize($value === null ? null : (string) $value);
        if ($code !== null && ! self::isValid($code)) {
            throw self::invalid($field);
        }

        return $code;
    }

    /** The active (or disabled, not merged) student of the school who holds the code. */
    public static function holder(int $schoolId, string $code, ?int $exceptId = null): ?User
    {
        return User::query()
            ->where('school_id', $schoolId)
            ->where('student_code', $code)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();
    }

    /** 422 student_code_taken with the holder, so the app can offer "เพิ่มคนนี้เข้าห้องแทน". */
    public static function taken(User $holder, string $code, string $field): ApiException
    {
        $message = 'เลขประจำตัว '.$code.' เป็นของ '.$holder->name.' อยู่แล้ว';

        return new ApiException($message, 'student_code_taken', 422, [$field => [$message]], [
            'existing_student' => ['id' => $holder->id, 'name' => $holder->name],
        ]);
    }

    private static function invalid(string $field): ApiException
    {
        $message = 'เลขประจำตัวใช้ได้เฉพาะตัวเลข ตัวอักษรอังกฤษ และขีด ไม่เกิน '.self::MAX_LENGTH.' ตัว';

        return new ApiException($message, 'validation_failed', 422, [$field => [$message]]);
    }
}
