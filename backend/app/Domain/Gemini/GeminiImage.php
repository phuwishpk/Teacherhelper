<?php

namespace App\Domain\Gemini;

/** An image part of a request (sent as inlineData, base64). */
final readonly class GeminiImage
{
    public const WEBP = 'image/webp';

    public function __construct(
        public string $data,
        public string $mimeType = self::WEBP,
    ) {}
}
