<?php

namespace App\Domain\Gemini;

use App\Models\TeacherApiKey;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * Which key pays for a Gemini call (DESIGN §10.1):
 *   1. the key of the teacher who owns the classroom (teacher_api_keys, APP_KEY-encrypted)
 *   2. the server key GEMINI_API_KEY, when set
 *   3. none: the caller marks the work `manual` with reason ai_key_missing
 *
 * A stored key that no longer decrypts (APP_KEY rotated) counts as absent
 * and is logged by user id only.
 */
final class GeminiKeyResolver
{
    public function forTeacher(?int $teacherId): ?GeminiKey
    {
        return $this->teacherKey($teacherId) ?? $this->serverKey();
    }

    public function teacherKey(?int $teacherId): ?GeminiKey
    {
        if ($teacherId === null) {
            return null;
        }
        $row = TeacherApiKey::query()->find($teacherId);
        if ($row === null) {
            return null;
        }

        try {
            $key = trim((string) $row->encrypted_key);
        } catch (DecryptException) {
            Log::warning('gemini.teacher_key_undecryptable', ['user_id' => $teacherId]);

            return null;
        }

        return $key === '' ? null : new GeminiKey($key, GeminiKey::SOURCE_TEACHER);
    }

    public function serverKey(): ?GeminiKey
    {
        $key = trim((string) config('services.gemini.api_key'));

        return $key === '' ? null : new GeminiKey($key, GeminiKey::SOURCE_SERVER);
    }

    public function serverKeyAvailable(): bool
    {
        return $this->serverKey() !== null;
    }
}
