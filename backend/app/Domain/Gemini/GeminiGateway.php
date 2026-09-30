<?php

namespace App\Domain\Gemini;

use App\Models\AiCall;
use Illuminate\Support\Str;
use JsonException;

/**
 * Everything around a Gemini call except the transport (DESIGN §7.2, §10):
 *
 * 1. sends the batch through GeminiClient (Http::pool, up to 8 at a time);
 * 2. decodes the JSON and checks it against the request's schema, then the
 *    call's semantic check;
 * 3. invalid output (an answer cut off at maxOutputTokens included) is
 *    retried once; still invalid -> invalid_output;
 * 4. logs every request, retries included, to `ai_calls` (purpose, model,
 *    prompt_version, tokens, latency, status, key_source, and the §21.8
 *    labels: feature, media resolution, image and question counts) and
 *    never the prompt, the images or the key.
 *
 * Transport errors are not retried here: GradeScanJob counts attempts and
 * releases itself with backoff.
 */
final class GeminiGateway
{
    private const ERROR_LIMIT = 500;

    private const MAX_TOKENS = 'MAX_TOKENS';

    public function __construct(private readonly GeminiClient $client) {}

    public function client(): GeminiClient
    {
        return $this->client;
    }

    /**
     * passes = 1 skips the retry of invalid output (the caller already had
     * its first try, e.g. in a batch).
     *
     * @param  array<array-key, GeminiCall>  $calls
     * @return array<array-key, CallOutcome> same keys
     */
    public function run(array $calls, GeminiKey $key, int $passes = 2): array
    {
        $outcomes = [];
        $pending = $calls;
        for ($pass = 1; $pass <= $passes && $pending !== []; $pass++) {
            $replies = $this->client->generate(
                array_map(fn (GeminiCall $call) => $call->request, $pending),
                $key->apiKey,
            );

            $retry = [];
            foreach ($pending as $id => $call) {
                $reply = $replies[$id] ?? GeminiReply::error('no reply for this request');
                $outcome = self::judge($call, $reply);
                $this->log($call, $reply, $outcome, $key);

                if ($outcome->status === CallOutcome::INVALID_OUTPUT && $pass < $passes) {
                    $retry[$id] = $call;

                    continue;
                }
                $outcomes[$id] = $outcome;
            }
            $pending = $retry;
        }

        return $outcomes;
    }

    /**
     * One reply that came back from the Batch API (DESIGN §20.8): judged
     * like any call and logged to ai_calls with batch = TRUE. No retry here:
     * the caller decides (StudentAnalyses retries invalid output once as an
     * ordinary call).
     */
    public function judgeBatchReply(GeminiCall $call, GeminiReply $reply, GeminiKey $key): CallOutcome
    {
        $outcome = self::judge($call, $reply);
        $this->log($call, $reply, $outcome, $key, batch: true);

        return $outcome;
    }

    /** One call; throws unless the outcome is ok. */
    public function runOne(GeminiCall $call, GeminiKey $key): CallOutcome
    {
        $outcome = $this->run(['one' => $call], $key)['one'];
        if (! $outcome->isOk()) {
            throw new GeminiException((string) $outcome->error, match ($outcome->status) {
                CallOutcome::INVALID_OUTPUT => GeminiException::INVALID_OUTPUT,
                CallOutcome::KEY_INVALID => GeminiException::KEY_INVALID,
                default => GeminiException::ERROR,
            });
        }

        return $outcome;
    }

    private static function judge(GeminiCall $call, GeminiReply $reply): CallOutcome
    {
        if ($reply->status === GeminiReply::KEY_REJECTED) {
            return new CallOutcome(CallOutcome::KEY_INVALID, null, $reply->error);
        }
        if (! $reply->isOk()) {
            return new CallOutcome(CallOutcome::ERROR, null, $reply->error);
        }
        if ($reply->finishReason === self::MAX_TOKENS) {
            // Cut off at maxOutputTokens (DESIGN §21.6): never use half an answer.
            return new CallOutcome(CallOutcome::INVALID_OUTPUT, null, 'output cut off at maxOutputTokens (finishReason MAX_TOKENS)');
        }

        try {
            $data = json_decode(self::stripFence((string) $reply->text), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new CallOutcome(CallOutcome::INVALID_OUTPUT, null, 'output is not JSON: '.$e->getMessage());
        }
        if (! is_array($data)) {
            return new CallOutcome(CallOutcome::INVALID_OUTPUT, null, 'output is not a JSON object');
        }

        $schema = $call->validationSchema ?? $call->request->responseSchema;
        if ($schema !== null) {
            $errors = SchemaValidator::validate($schema, $data);
            if ($errors !== []) {
                return new CallOutcome(CallOutcome::INVALID_OUTPUT, null, 'schema: '.implode('; ', array_slice($errors, 0, 5)));
            }
        }

        if ($call->check !== null) {
            try {
                $data = ($call->check)($data);
            } catch (GeminiException $e) {
                return new CallOutcome(CallOutcome::INVALID_OUTPUT, null, $e->getMessage());
            }
        }

        return new CallOutcome(CallOutcome::OK, $data);
    }

    /** Structured output is raw JSON; tolerate a ```json fence all the same. */
    private static function stripFence(string $text): string
    {
        $text = trim($text);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s', $text, $m) === 1) {
            return $m[1];
        }

        return $text;
    }

    private function log(GeminiCall $call, GeminiReply $reply, CallOutcome $outcome, GeminiKey $key, bool $batch = false): void
    {
        AiCall::create([
            'purpose' => $call->request->purpose,
            'response_id' => $call->responseId,
            'question_id' => $call->questionId,
            'skill_id' => $call->skillId,
            'model' => Str::limit($this->client->model(), 64, ''),
            'prompt_version' => Str::limit($call->request->promptVersion, 20, ''),
            'key_source' => $key->source,
            'input_tokens' => $reply->inputTokens,
            'output_tokens' => $reply->outputTokens,
            'latency_ms' => $reply->latencyMs,
            'status' => match ($outcome->status) {
                CallOutcome::OK => AiCall::STATUS_OK,
                CallOutcome::INVALID_OUTPUT => AiCall::STATUS_INVALID_OUTPUT,
                default => AiCall::STATUS_ERROR,
            },
            'error' => $outcome->error === null ? null : Str::limit(self::redact($outcome->error, $key), self::ERROR_LIMIT),
            'feature' => $call->feature === null ? null : Str::limit($call->feature, 40, ''),
            'cached_tokens' => $reply->cachedTokens,
            'thinking_tokens' => $reply->thinkingTokens,
            'media_resolution' => MediaResolution::summary(array_map(fn (GeminiImage $i) => $i->mediaResolution, $call->request->images)),
            'image_count' => $call->request->images === [] ? null : min(255, count($call->request->images)),
            'question_count' => $call->questionCount === null ? null : min(255, $call->questionCount),
            'assignment_id' => $call->assignmentId,
            'batch' => $batch,
        ]);
    }

    /** Belt and braces: an error text must never carry the key. */
    private static function redact(string $message, GeminiKey $key): string
    {
        return $key->apiKey === '' ? $message : str_replace($key->apiKey, '[redacted]', $message);
    }
}
