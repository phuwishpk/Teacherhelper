<?php

namespace App\Domain\Students;

use App\Exceptions\ApiException;
use App\Models\StudentCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

/**
 * Student login (DESIGN §7.4, §9.1, §29.10): a username and password, or the
 * QR card. Both return a Sanctum token with the `student` ability that lives
 * 180 days.
 *
 * Every failure of the password path answers with the same generic message
 * so the endpoint cannot be used to enumerate usernames; the lockout is the
 * only distinguishable state and it is per student, not per IP (the route
 * throttle handles IPs).
 */
class StudentAuthenticator
{
    public const TOKEN_NAME = 'student-app';

    /** Payload of the login card (`EVL1.{token}`) or the bare token. */
    public function loginWithQr(string $qrPayload, ?string $deviceName = null): NewAccessToken
    {
        return $this->issueToken($this->studentByQr($qrPayload), $deviceName);
    }

    /**
     * The active student of a QR card, with every check of the QR login but
     * no token (also the first Google link of DESIGN §24.9.5).
     */
    public function studentByQr(string $qrPayload): User
    {
        $token = CredentialIssuer::tokenFromPayload($qrPayload);

        $credential = $token === ''
            ? null
            : StudentCredential::query()->where('qr_token_hash', CredentialIssuer::hashQrToken($token))->first();

        if ($credential === null) {
            throw new ApiException('บัตร QR นี้ใช้ไม่ได้ กรุณาขอบัตรใหม่จากครู', 'qr_invalid', 422, [
                'qr_token' => ['บัตร QR นี้ใช้ไม่ได้'],
            ]);
        }

        $student = $credential->student;
        $this->assertActive($student);

        return $student;
    }

    public function loginWithPassword(string $username, string $password, ?string $deviceName = null): NewAccessToken
    {
        return $this->issueToken($this->studentByPassword($username, $password), $deviceName);
    }

    /**
     * The active student behind a username and password (DESIGN §29.10), with
     * one generic error, the failure counter and the lockout, but no token
     * (also the first Google link of DESIGN §24.9.5).
     */
    public function studentByPassword(string $username, string $password): User
    {
        $student = User::query()->where('role', User::ROLE_STUDENT)->where('username', StudentUsernames::normalize($username))->first();

        return $this->checked($student, $password);
    }

    private function checked(?User $student, string $password): User
    {
        $credential = $student?->credential;

        if ($student === null || $credential === null) {
            throw self::invalidCredentials();
        }

        if ($credential->isLocked()) {
            throw self::locked($credential);
        }

        if (! Hash::check($password, $credential->pin_hash)) {
            $credential = $this->recordFailure($credential);

            throw $credential->isLocked() ? self::locked($credential) : self::invalidCredentials();
        }

        $this->assertActive($student);

        $credential->forceFill(['failed_pin_attempts' => 0, 'locked_until' => null])->save();

        return $student;
    }

    /**
     * Counts a wrong PIN; the 5th one (CredentialIssuer::MAX_FAILED_PIN_ATTEMPTS)
     * locks the student for 15 minutes and the counter starts over afterwards.
     *
     * The row is re-read under a row lock so parallel wrong PINs for one
     * student cannot each see the same counter and slip past the 5 attempts
     * (the lock is a no-op on SQLite). Returns the row as saved.
     */
    private function recordFailure(StudentCredential $credential): StudentCredential
    {
        return DB::transaction(function () use ($credential) {
            $row = StudentCredential::query()->lockForUpdate()->find($credential->student_id) ?? $credential;

            $row->failed_pin_attempts++;

            if ($row->failed_pin_attempts >= CredentialIssuer::MAX_FAILED_PIN_ATTEMPTS) {
                $row->failed_pin_attempts = 0;
                $row->locked_until = now()->addMinutes(CredentialIssuer::LOCKOUT_MINUTES);
            }

            $row->save();

            return $row;
        });
    }

    /** The student token of every login path: ability `student`, 180 days. */
    public function issueToken(User $student, ?string $deviceName): NewAccessToken
    {
        return $student->createToken(
            $deviceName ?: self::TOKEN_NAME,
            ['student'],
            now()->addDays((int) config('eduvision.token_ttl_days.student', 180)),
        );
    }

    private function assertActive(User $student): void
    {
        if (! $student->isStudent() || ! $student->isActive()) {
            throw new ApiException('บัญชีนักเรียนนี้ถูกระงับการใช้งาน', 'account_not_active', 403);
        }
    }

    private static function invalidCredentials(): ApiException
    {
        return new ApiException('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 'invalid_credentials', 422, [
            'password' => ['ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],
        ]);
    }

    private static function locked(StudentCredential $credential): ApiException
    {
        $seconds = max(1, (int) now()->diffInSeconds($credential->locked_until, false));

        return new ApiException(
            'ใส่รหัสผ่านผิดหลายครั้ง ระบบล็อกชั่วคราว 15 นาที หรือให้ครูรีเซ็ตรหัสผ่าน',
            'pin_locked',
            423,
            ['password' => ['ล็อกชั่วคราว ลองใหม่ในอีก '.(int) ceil($seconds / 60).' นาที']],
        );
    }
}
