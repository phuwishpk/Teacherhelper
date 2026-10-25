<?php

namespace App\Domain\Gemini;

use RuntimeException;

/**
 * A Gemini call failed. status:
 *   error           transport or HTTP failure (ai_calls.status = error)
 *   invalid_output  output that does not satisfy the schema (ai_calls.status = invalid_output)
 *   key_invalid     Google rejected the API key (400 API_KEY_INVALID, 401, 403)
 *   key_missing     neither the teacher nor the server has a key (DESIGN §10.1)
 */
class GeminiException extends RuntimeException
{
    public const ERROR = 'error';

    public const INVALID_OUTPUT = 'invalid_output';

    public const KEY_INVALID = 'key_invalid';

    public const KEY_MISSING = 'key_missing';

    /**
     * @param  string|null  $errorCode  AiErrorCode of the failure (DESIGN §36)
     * @param  int|null  $aiCallId  the ai_calls row of the failed call, when one was logged
     */
    public function __construct(
        string $message,
        public readonly string $status = self::ERROR,
        public readonly ?string $errorCode = null,
        public readonly ?int $aiCallId = null,
    ) {
        parent::__construct($message);
    }

    /** The code to show: the one given, else the one of the status. */
    public function code(): string
    {
        return $this->errorCode ?? match ($this->status) {
            self::KEY_INVALID => AiErrorCode::KEY_INVALID,
            self::KEY_MISSING => AiErrorCode::KEY_MISSING,
            self::INVALID_OUTPUT => AiErrorCode::OUTPUT,
            default => AiErrorCode::forTransport(null, $this->getMessage()),
        };
    }

    public static function invalidOutput(string $message): self
    {
        return new self($message, self::INVALID_OUTPUT);
    }
}
