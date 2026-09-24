<?php

namespace App\Domain\Students;

use App\Models\StudentCredential;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

/**
 * Issues and rotates student credentials (DESIGN §7.4, §8.1).
 *
 * The plain QR token and PIN exist only in the return values of this class:
 * the database keeps SHA-256(token) and bcrypt(PIN). Every rotation revokes
 * all of the student's Sanctum tokens (§7.4).
 */
class CredentialIssuer
{
    /** Prefix of the login-card QR payload (DESIGN §5.4): `EVL1.{token}`. */
    public const QR_PREFIX = 'EVL1.';

    public const PIN_LENGTH = 6;

    /** Failed PIN attempts before the lockout (DESIGN §7.4). */
    public const MAX_FAILED_PIN_ATTEMPTS = 5;

    /** Lockout duration in minutes (DESIGN §7.4). */
    public const LOCKOUT_MINUTES = 15;

    /**
     * Creates the credential row of a brand-new student.
     *
     * @return array{qr_token: string, pin: string}
     */
    public function create(User $student): array
    {
        $qrToken = self::randomQrToken();
        $pin = self::randomPin();

        StudentCredential::create([
            'student_id' => $student->id,
            'qr_token_hash' => self::hashQrToken($qrToken),
            'qr_issued_at' => now(),
            'pin_hash' => Hash::make($pin),
            'failed_pin_attempts' => 0,
            'locked_until' => null,
        ]);

        return ['qr_token' => $qrToken, 'pin' => $pin];
    }

    /**
     * Issues a new QR token (a new login card) and revokes every existing
     * session of the student. Returns the plain token for the card renderer.
     */
    public function issueQrToken(User $student): string
    {
        $qrToken = self::randomQrToken();

        DB::transaction(function () use ($student, $qrToken) {
            $student->credential()->updateOrCreate(
                ['student_id' => $student->id],
                [
                    'qr_token_hash' => self::hashQrToken($qrToken),
                    'qr_issued_at' => now(),
                ],
            );
            $student->tokens()->delete();
        });

        return $qrToken;
    }

    /**
     * Resets the PIN, clears the lockout and revokes every session. Returns the
     * plain PIN, which the teacher sees exactly once.
     */
    public function issuePin(User $student): string
    {
        $pin = self::randomPin();

        DB::transaction(function () use ($student, $pin) {
            $student->credential()->updateOrCreate(
                ['student_id' => $student->id],
                [
                    'pin_hash' => Hash::make($pin),
                    'failed_pin_attempts' => 0,
                    'locked_until' => null,
                ],
            );
            $student->tokens()->delete();
        });

        return $pin;
    }

    public static function hashQrToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** `EVL1.{token}` as printed on the card (DESIGN §5.4). */
    public static function qrPayload(string $token): string
    {
        return self::QR_PREFIX.$token;
    }

    /** Accepts the full card payload or the bare token and returns the bare token. */
    public static function tokenFromPayload(string $payload): string
    {
        $payload = trim($payload);

        return str_starts_with($payload, self::QR_PREFIX)
            ? substr($payload, strlen(self::QR_PREFIX))
            : $payload;
    }

    /** 32 random bytes, base64url without padding (43 chars), per DESIGN §5.4. */
    public static function randomQrToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function randomPin(): string
    {
        return str_pad((string) random_int(0, 10 ** self::PIN_LENGTH - 1), self::PIN_LENGTH, '0', STR_PAD_LEFT);
    }
}
