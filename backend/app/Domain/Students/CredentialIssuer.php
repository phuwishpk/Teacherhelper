<?php

namespace App\Domain\Students;

use App\Models\ClassroomStudent;
use App\Models\StudentCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

    /**
     * The password of a new student and of a reset (DESIGN §29.10). It is not
     * a secret: must_change_password makes the student replace it at the
     * next sign-in, and nothing else opens until they do.
     */
    public const INITIAL_PASSWORD = '123456';

    public const MIN_PASSWORD_LENGTH = 6;

    /**
     * bcrypt cost of PIN hashes. A 6-digit PIN has only 10^6 values, so a high
     * cost buys nothing (the 5-attempt lockout below is the real guard), while
     * a bulk enrolment hashes one PIN per row inside a single request: at
     * BCRYPT_ROUNDS=12 that was ~270 ms per row (100 rows = 27 s, past the
     * app's 20 s timeout), at cost 8 it is ~15 ms. Teacher passwords keep the
     * configured BCRYPT_ROUNDS.
     */
    public const PIN_HASH_ROUNDS = 8;

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
        $pin = self::INITIAL_PASSWORD;

        StudentCredential::create([
            'student_id' => $student->id,
            'qr_token_hash' => self::hashQrToken($qrToken),
            'qr_issued_at' => now(),
            'pin_hash' => self::hashPin($pin),
            'failed_pin_attempts' => 0,
            'locked_until' => null,
            'must_change_password' => true,
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

        DB::transaction(fn () => $this->rotateQrToken($student, $qrToken));

        return $qrToken;
    }

    /**
     * Stores the hash of a freshly generated QR token and revokes every session
     * of the student (DESIGN §7.4). This is the only place that rule lives:
     * RenderLoginCardsJob calls it, inside its own transaction, after the PDF
     * with the plain token has been rendered and stored.
     */
    public function rotateQrToken(User $student, string $plainToken): void
    {
        $student->credential()->updateOrCreate(
            ['student_id' => $student->id],
            [
                'qr_token_hash' => self::hashQrToken($plainToken),
                'qr_issued_at' => now(),
            ],
        );
        $student->tokens()->delete();
    }

    /**
     * Resets the PIN, clears the lockout and revokes every session. Returns the
     * plain PIN, which the teacher sees exactly once: a student the background
     * roster sync added (classroom_students.pin_pending_at) has one now.
     */
    public function issuePin(User $student): string
    {
        $pin = self::INITIAL_PASSWORD;

        DB::transaction(function () use ($student, $pin) {
            $student->credential()->updateOrCreate(
                ['student_id' => $student->id],
                [
                    'pin_hash' => self::hashPin($pin),
                    'failed_pin_attempts' => 0,
                    'locked_until' => null,
                    'must_change_password' => true,
                ],
            );
            $student->tokens()->delete();
            ClassroomStudent::query()->where('student_id', $student->id)->whereNotNull('pin_pending_at')->update(['pin_pending_at' => null]);
        });

        return $pin;
    }

    /**
     * The student's own new password: stored at the configured bcrypt cost,
     * the lockout cleared, and every other session revoked.
     */
    public function setPassword(User $student, string $password, ?int $keepTokenId = null): void
    {
        DB::transaction(function () use ($student, $password, $keepTokenId) {
            $student->credential()->update([
                'pin_hash' => Hash::make($password),
                'failed_pin_attempts' => 0,
                'locked_until' => null,
                'must_change_password' => false,
            ]);
            $student->tokens()->when($keepTokenId !== null, fn ($q) => $q->whereKeyNot($keepTokenId))->delete();
        });
    }

    public static function hashQrToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** bcrypt at PIN_HASH_ROUNDS; verified with Hash::check() like any bcrypt hash. */
    public static function hashPin(string $pin): string
    {
        return Hash::make($pin, ['rounds' => self::PIN_HASH_ROUNDS]);
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
}
