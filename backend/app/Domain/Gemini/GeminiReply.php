<?php

namespace App\Domain\Gemini;

/**
 * What came back for one request. status `ok` carries the model's text (not
 * yet validated); `error` a transport/HTTP failure; `key_rejected` means
 * Google refused the API key, so retrying with it is pointless.
 */
final readonly class GeminiReply
{
    public const OK = 'ok';

    public const ERROR = 'error';

    public const KEY_REJECTED = 'key_rejected';

    public function __construct(
        public string $status,
        public ?string $text = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $latencyMs = null,
        public ?string $error = null,
        public ?int $httpStatus = null,
    ) {}

    public static function ok(string $text, ?int $inputTokens = null, ?int $outputTokens = null, ?int $latencyMs = null): self
    {
        return new self(self::OK, $text, $inputTokens, $outputTokens, $latencyMs, null, 200);
    }

    public static function error(string $error, ?int $latencyMs = null, ?int $httpStatus = null): self
    {
        return new self(self::ERROR, null, null, null, $latencyMs, $error, $httpStatus);
    }

    public static function keyRejected(string $error, ?int $latencyMs = null, ?int $httpStatus = null): self
    {
        return new self(self::KEY_REJECTED, null, null, null, $latencyMs, $error, $httpStatus);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
