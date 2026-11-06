<?php

namespace App\Domain\Classrooms;

use App\Models\Classroom;

/**
 * 6-character class codes students type for PIN login (DESIGN §8.1).
 * Alphabet omits 0/O and 1/I so a code read from a whiteboard is unambiguous.
 */
final class ClassCodeGenerator
{
    public const LENGTH = 6;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function random(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** A code no classroom uses yet. */
    public static function unique(): string
    {
        do {
            $code = self::random();
        } while (Classroom::query()->where('class_code', $code)->exists());

        return $code;
    }

    /** Uppercases and strips whitespace so students can type the code sloppily. */
    public static function normalize(string $input): string
    {
        return strtoupper(preg_replace('/\s+/', '', $input) ?? '');
    }
}
