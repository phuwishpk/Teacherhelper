<?php

namespace App\Domain\Gemini;

/**
 * Result of one Gemini call after validation (GeminiGateway). status:
 * ok (data holds the validated, normalised JSON), error, invalid_output
 * (still invalid after the one retry) or key_invalid (Google rejected the key).
 */
final readonly class CallOutcome
{
    public const OK = 'ok';

    public const ERROR = 'error';

    public const INVALID_OUTPUT = 'invalid_output';

    public const KEY_INVALID = 'key_invalid';

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public string $status,
        public ?array $data = null,
        public ?string $error = null,
    ) {}

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
