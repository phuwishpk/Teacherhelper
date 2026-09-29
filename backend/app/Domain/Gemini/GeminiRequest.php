<?php

namespace App\Domain\Gemini;

/**
 * One generateContent call, transport-neutral. Built by the prompt factories
 * from a PromptRepository file; the HTTP client turns it into
 * {systemInstruction, contents: [user text + inlineData images],
 *  generationConfig: {responseMimeType, responseJsonSchema, ...}}.
 *
 * hints repeat, as structured data, what the prompt text already says (the
 * key, the criterion ids): FakeGeminiClient reads them to answer
 * plausibly. They are never sent to Google.
 *
 * timeout: seconds for this call instead of GEMINI_TIMEOUT (a whole page
 * with every question takes longer than one crop).
 */
final readonly class GeminiRequest
{
    /**
     * @param  list<GeminiImage>  $images
     * @param  array<string, mixed>|null  $responseSchema  JSON Schema of the expected output (DESIGN §10.3)
     * @param  array<string, mixed>  $hints
     */
    public function __construct(
        public string $purpose,
        public string $type,
        public string $promptVersion,
        public string $systemInstruction,
        public string $userText,
        public array $images = [],
        public ?array $responseSchema = null,
        public ?float $temperature = null,
        public array $hints = [],
        public ?int $timeout = null,
    ) {}
}
