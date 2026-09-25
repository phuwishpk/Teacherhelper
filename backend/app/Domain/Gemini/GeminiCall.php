<?php

namespace App\Domain\Gemini;

use Closure;

/**
 * A request plus what GeminiGateway needs around it: the ai_calls links and
 * the semantic check that runs after the schema check. The check returns the
 * normalised data or throws GeminiException::invalidOutput().
 */
final readonly class GeminiCall
{
    /**
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $check
     */
    public function __construct(
        public GeminiRequest $request,
        public ?int $responseId = null,
        public ?int $questionId = null,
        public ?int $skillId = null,
        public ?Closure $check = null,
    ) {}
}
