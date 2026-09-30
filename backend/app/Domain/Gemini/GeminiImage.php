<?php

namespace App\Domain\Gemini;

/**
 * An image or PDF part of a request (sent as inlineData, base64).
 *
 * mediaResolution: low | medium | high (MediaResolution), null = Gemini's
 * default. label: a short text sent as its own part right before the image
 * (e.g. "Q3 answer box"), so one call can carry the crops of several
 * questions (extract_batch, DESIGN §21.4).
 */
final readonly class GeminiImage
{
    public const WEBP = 'image/webp';

    public const PDF = 'application/pdf';

    public function __construct(
        public string $data,
        public string $mimeType = self::WEBP,
        public ?string $mediaResolution = null,
        public ?string $label = null,
    ) {}
}
