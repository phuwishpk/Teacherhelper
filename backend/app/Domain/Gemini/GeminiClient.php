<?php

namespace App\Domain\Gemini;

/**
 * Transport to Gemini (DESIGN §10.1): HttpGeminiClient (Laravel HTTP client,
 * POST .../models/{GEMINI_MODEL}:generateContent with x-goog-api-key) or
 * FakeGeminiClient (GEMINI_FAKE=true: offline, deterministic).
 *
 * The client only moves requests and replies. Prompts, schema validation,
 * the retry of invalid output and `ai_calls` logging live in GeminiGateway,
 * so they run the same way against the fake.
 */
interface GeminiClient
{
    /** Model id written to ai_calls.model. */
    public function model(): string;

    /**
     * Sends every request as its own generateContent call, up to
     * services.gemini.concurrency at a time (Http::pool, DESIGN §7.2), so
     * one failure never affects the others. Never throws for a single call.
     *
     * @param  array<array-key, GeminiRequest>  $requests
     * @return array<array-key, GeminiReply> one reply per request, same keys
     */
    public function generate(array $requests, #[\SensitiveParameter] string $apiKey): array;

    /**
     * models.list with the given key: the cheap check behind PUT /me/ai-key
     * and `eduvision:gemini-check`.
     *
     * @return list<string> model ids without the "models/" prefix
     *
     * @throws GeminiException key_invalid when Google rejects the key, error otherwise
     */
    public function listModels(#[\SensitiveParameter] string $apiKey): array;
}
