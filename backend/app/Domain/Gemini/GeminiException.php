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

    public function __construct(string $message, public readonly string $status = self::ERROR)
    {
        parent::__construct($message);
    }

    public static function invalidOutput(string $message): self
    {
        return new self($message, self::INVALID_OUTPUT);
    }
}
