<?php

namespace App\Domain\Students;

use App\Models\User;

/**
 * The sign-in name of a student (DESIGN §29.10): lower case, 3-40
 * characters of a-z, 0-9, dot, dash and underscore, unique across the
 * installation. The system proposes one when a student is created; the
 * teacher may change it.
 */
final class StudentUsernames
{
    public const PATTERN = '/^[a-z0-9][a-z0-9._-]{2,39}$/';

    public static function normalize(string $username): string
    {
        return strtolower(trim($username));
    }

    public static function valid(string $username): bool
    {
        return preg_match(self::PATTERN, $username) === 1;
    }

    public static function taken(string $username, ?int $exceptUserId = null): bool
    {
        return User::query()
            ->where('username', $username)
            ->when($exceptUserId !== null, fn ($q) => $q->whereKeyNot($exceptUserId))
            ->exists();
    }

    /** The student code when it can be a username and nobody has it, otherwise s + 7 digits. */
    public static function generate(?string $studentCode = null): string
    {
        $fromCode = self::normalize((string) $studentCode);
        if (self::valid($fromCode) && ! self::taken($fromCode)) {
            return $fromCode;
        }
        do {
            $username = 's'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        } while (self::taken($username));

        return $username;
    }
}
