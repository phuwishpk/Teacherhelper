<?php

namespace App\Domain\Gemini;

/**
 * A usable Gemini API key and whose it is (ai_calls.key_source, DESIGN §10.1).
 * Lives only in memory for the duration of a call: never logged, serialised,
 * queued or returned to a client.
 */
final readonly class GeminiKey
{
    public const SOURCE_TEACHER = 'teacher';

    public const SOURCE_SERVER = 'server';

    public function __construct(
        #[\SensitiveParameter] public string $apiKey,
        public string $source,
    ) {}

    public function last4(): string
    {
        return substr($this->apiKey, -4);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['source' => $this->source, 'api_key' => '••••'.$this->last4()];
    }

    public function __serialize(): array
    {
        throw new \LogicException('A Gemini key must never be serialised.');
    }
}
