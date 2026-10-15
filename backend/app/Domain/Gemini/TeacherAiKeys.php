<?php

namespace App\Domain\Gemini;

use App\Models\TeacherApiKey;
use App\Models\User;

/**
 * The teacher's own Gemini key (DESIGN §9.1, §10.1): verified with a
 * models.list call before it is stored, kept encrypted with APP_KEY, shown
 * only as its last 4 characters. The key never leaves the server again.
 */
final class TeacherAiKeys
{
    public function __construct(
        private readonly GeminiClient $client,
        private readonly GeminiKeyResolver $keys,
    ) {}

    /**
     * {provider, configured, key_last4, last_verified_at, server_key_available}
     *
     * @return array<string, mixed>
     */
    public function status(User $teacher): array
    {
        $row = TeacherApiKey::query()->find($teacher->id);

        return [
            'provider' => TeacherApiKey::PROVIDER_GEMINI,
            'configured' => $row !== null,
            'key_last4' => $row?->key_last4,
            'last_verified_at' => $row?->last_verified_at?->toIso8601String(),
            'server_key_available' => $this->keys->serverKeyAvailable(),
        ];
    }

    /**
     * @throws GeminiException key_invalid (Google refused it) or error (Gemini unreachable)
     */
    public function save(User $teacher, #[\SensitiveParameter] string $apiKey): void
    {
        $this->client->listModels($apiKey);

        TeacherApiKey::query()->updateOrCreate(
            ['user_id' => $teacher->id],
            [
                'provider' => TeacherApiKey::PROVIDER_GEMINI,
                'encrypted_key' => $apiKey,
                'key_last4' => substr($apiKey, -4),
                'last_verified_at' => now(),
            ],
        );
    }

    public function delete(User $teacher): void
    {
        TeacherApiKey::query()->whereKey($teacher->id)->delete();
    }
}
