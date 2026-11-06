<?php

namespace App\Domain\Gemini;

/**
 * Result of one Gemini call after validation (GeminiGateway). status:
 * ok (data holds the validated, normalised JSON), error, invalid_output
 * (still invalid after the one retry) or key_invalid (Google rejected the key).
 * A failure carries its error code (AiErrorCode) and the ai_calls id.
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
        public ?string $errorCode = null,
        public ?int $aiCallId = null,
    ) {}

    /** The same outcome with its ai_calls row and error code (DESIGN §36). */
    public function logged(int $aiCallId, ?string $errorCode): self
    {
        return new self($this->status, $this->data, $this->error, $errorCode, $aiCallId);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
