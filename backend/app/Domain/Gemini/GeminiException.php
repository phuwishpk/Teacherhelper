<?php

namespace App\Domain\Gemini;

use RuntimeException;

/**
 * A Gemini call failed or returned output that does not satisfy the schema
 * (`ai_calls.status` = error | invalid_output, DESIGN §8.4).
 */
class GeminiException extends RuntimeException
{
    public const ERROR = 'error';

    public const INVALID_OUTPUT = 'invalid_output';

    public function __construct(string $message, public readonly string $status = self::ERROR)
    {
        parent::__construct($message);
    }

    public static function invalidOutput(string $message): self
    {
        return new self($message, self::INVALID_OUTPUT);
    }
}
