<?php

namespace App\Domain\Gemini;

/**
 * The Gemini Batch API (DESIGN §20.8, half the price, results within 24 h),
 * used only by the nightly student analysis, never for grading (§21):
 *
 *   POST {base}/models/{model}:batchGenerateContent
 *        {batch: {display_name, input_config: {requests: {requests:
 *          [{request: <generateContent body>, metadata: {key}}]}}}}
 *   GET  {base}/{batches/…}   -> metadata.state, response.inlinedResponses
 *
 * Implemented by HttpGeminiClient and FakeGeminiClient (which answers at
 * once). The key travels only in the x-goog-api-key header.
 */
interface GeminiBatchClient
{
    /**
     * @param  array<string, GeminiRequest>  $requests  keyed by the metadata key echoed in the results
     *
     * @throws GeminiException key_invalid when Google rejects the key, error otherwise
     */
    public function submitBatch(array $requests, string $displayName, #[\SensitiveParameter] string $apiKey): GeminiBatch;

    /**
     * @throws GeminiException key_invalid when Google rejects the key, error otherwise
     */
    public function batchStatus(string $name, #[\SensitiveParameter] string $apiKey): GeminiBatch;
}
