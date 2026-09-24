<?php

namespace App\Domain\Students;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\StudentCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

/**
 * Student login (DESIGN §7.4, §9.1): the QR card is the main way, class code +
 * student number + PIN the fallback. Both return a Sanctum token with the
 * `student` ability that lives 180 days.
 *
 * Every failure of the PIN path answers with the same generic message so the
 * endpoint cannot be used to enumerate class codes or student numbers; the
 * lockout is the only distinguishable state and it is per student, not per IP
 * (the route throttle handles IPs).
 */
class StudentAuthenticator
{
    public const TOKEN_NAME = 'student-app';

    /** Payload of the login card (`EVL1.{token}`) or the bare token. */
    public function loginWithQr(string $qrPayload, ?string $deviceName = null): NewAccessToken
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

        return $this->issueToken($student, $deviceName);
    }

    public function loginWithPin(string $classCode, int $studentNumber, string $pin, ?string $deviceName = null): NewAccessToken
    {
        $classroom = Classroom::query()->where('class_code', ClassCodeGenerator::normalize($classCode))->first();

        $student = $classroom?->students()->wherePivot('student_number', $studentNumber)->first();
        $credential = $student?->credential;

        if ($student === null || $credential === null) {
            throw self::invalidCredentials();
        }

        if ($credential->isLocked()) {
            throw self::locked($credential);
        }

        if (! Hash::check($pin, $credential->pin_hash)) {
            $this->recordFailure($credential);

            throw $credential->isLocked() ? self::locked($credential) : self::invalidCredentials();
        }

        $this->assertActive($student);

        $credential->forceFill(['failed_pin_attempts' => 0, 'locked_until' => null])->save();

        return $this->issueToken($student, $deviceName);
    }

    /**
     * Counts a wrong PIN; the 5th one (CredentialIssuer::MAX_FAILED_PIN_ATTEMPTS)
     * locks the student for 15 minutes and the counter starts over afterwards.
     */
    private function recordFailure(StudentCredential $credential): void
    {
        DB::transaction(function () use ($credential) {
            $credential->failed_pin_attempts++;

            if ($credential->failed_pin_attempts >= CredentialIssuer::MAX_FAILED_PIN_ATTEMPTS) {
                $credential->failed_pin_attempts = 0;
                $credential->locked_until = now()->addMinutes(CredentialIssuer::LOCKOUT_MINUTES);
            }

            $credential->save();
        });
    }

    private function issueToken(User $student, ?string $deviceName): NewAccessToken
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
        return new ApiException('รหัสห้อง เลขที่ หรือ PIN ไม่ถูกต้อง', 'invalid_credentials', 422, [
            'pin' => ['รหัสห้อง เลขที่ หรือ PIN ไม่ถูกต้อง'],
        ]);
    }

    private static function locked(StudentCredential $credential): ApiException
    {
        $seconds = max(1, (int) now()->diffInSeconds($credential->locked_until, false));

        return new ApiException(
            'ใส่ PIN ผิดหลายครั้ง ระบบล็อกชั่วคราว 15 นาที หรือให้ครูรีเซ็ต PIN',
            'pin_locked',
            423,
            ['pin' => ['ล็อกชั่วคราว ลองใหม่ในอีก '.(int) ceil($seconds / 60).' นาที']],
        );
    }
}
