<?php

namespace App\Domain\Gemini;

/**
 * The only way the backend talks to Gemini (DESIGN §10). Implementations:
 * FakeGeminiClient (default binding: canned, deterministic, no network) and
 * the real HTTP client, which also resolves the API key (teacher key first,
 * then the server key; GeminiKeyResolver, DESIGN §10.1) and logs `ai_calls`.
 *
 * Every method returns output that already passed the server-side schema
 * check; anything else is a GeminiException.
 */
interface GeminiClient
{
    /**
     * `rubric_draft` (DESIGN §10.4): reference steps for show_work, criteria
     * for open questions. The teacher edits and approves the result.
     *
     * @throws GeminiException
     */
    public function draftRubric(RubricDraftRequest $request): RubricDraft;
}
