<?php

namespace App\Domain\Qa;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The group code outside participants type at /usability (DESIGN §39.10).
 * The lead makes a new one in the Test Lab and tells it to the people
 * taking part; it is kept hashed, so it is shown once. No code means
 * sign-up is closed.
 */
final class QaSignupCode
{
    private const KEY = 'usability_signup_code';

    public static function isOpen(): bool
    {
        return self::hash() !== null;
    }

    /** A new six-digit code in place of the old one; returns it in clear. */
    public static function renew(): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        DB::table('qa_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => Hash::make($code), 'updated_at' => now(), 'created_at' => now()],
        );

        return $code;
    }

    public static function close(): void
    {
        DB::table('qa_settings')->where('key', self::KEY)->delete();
    }

    public static function matches(string $code): bool
    {
        $hash = self::hash();

        return $hash !== null && preg_match('/^\d{6}$/', $code) === 1 && Hash::check($code, $hash);
    }

    private static function hash(): ?string
    {
        $value = DB::table('qa_settings')->where('key', self::KEY)->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
