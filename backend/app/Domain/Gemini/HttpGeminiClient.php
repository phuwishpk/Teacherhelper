<?php

namespace App\Domain\Gemini;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The real Gemini transport (DESIGN §10.1): the Laravel HTTP client straight
 * to the REST API, no SDK.
 *
 *   POST {base}/models/{GEMINI_MODEL}:generateContent   header x-goog-api-key
 *   {systemInstruction, contents: [{role: user, parts: [text, inlineData...]}],
 *    generationConfig: {responseMimeType: application/json, responseJsonSchema,
 *                       thinkingConfig?, temperature?, mediaResolution?,
 *                       maxOutputTokens?}}
 *
 * responseJsonSchema is the response schema without its size and range
 * bounds (servingSchema()); the gateway enforces those on the reply.
 *
 * Thinking level: the request's own (prompt front matter, DESIGN §21.6),
 * else GEMINI_THINKING_LEVEL; none at all when that setting is empty.
 *
 * Media resolution (DESIGN §21.5): per image part when media_per_part is on,
 * else one generationConfig.mediaResolution at the highest level of the
 * call's parts. An image with a label gets that text as a part of its own
 * right before it.
 *
 * The key travels only in the header, so it never appears in a URL, a log
 * line or an exception message. Requests run through Http::pool with at most
 * `concurrency` in flight; each has its own timeout (30 s).
 */
final class HttpGeminiClient implements GeminiClient
{
    private const ERROR_LIMIT = 300;

    public function __construct(
        private readonly string $model,
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        private readonly int $timeout = 30,
        private readonly int $concurrency = 8,
        private readonly ?string $thinkingLevel = 'low',
        private readonly bool $sendTemperature = false,
        private readonly bool $mediaPerPart = false,
    ) {}

    public static function fromConfig(): self
    {
        $config = (array) config('services.gemini');

        return new self(
            model: (string) ($config['model'] ?? 'gemini-3.8-flash'),
            baseUrl: rtrim((string) ($config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/'),
            timeout: max(5, (int) ($config['timeout'] ?? 30)),
            concurrency: max(1, (int) ($config['concurrency'] ?? 8)),
            thinkingLevel: ($config['thinking_level'] ?? null) ?: null,
            sendTemperature: (bool) ($config['send_temperature'] ?? false),
            mediaPerPart: (bool) ($config['media_per_part'] ?? false),
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(array $requests, #[\SensitiveParameter] string $apiKey): array
    {
        if ($requests === []) {
            return [];
        }

        $url = $this->baseUrl.'/models/'.$this->model.':generateContent';
        $started = hrtime(true);
        $results = Http::pool(function (Pool $pool) use ($requests, $url, $apiKey) {
            foreach ($requests as $key => $request) {
                $pool->as((string) $key)
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout(10)
                    ->timeout(max($this->timeout, (int) $request->timeout))
                    ->post($url, $this->payload($request));
            }
        }, $this->concurrency);
        $elapsedMs = (int) round((hrtime(true) - $started) / 1e6);

        $replies = [];
        foreach ($requests as $key => $request) {
            $replies[$key] = $this->reply($results[(string) $key] ?? null, $elapsedMs);
        }

        return $replies;
    }

    public function listModels(#[\SensitiveParameter] string $apiKey): array
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($this->timeout)
                ->get($this->baseUrl.'/models', ['pageSize' => 1000]);
        } catch (ConnectionException $e) {
            throw new GeminiException('Gemini is unreachable: '.Str::limit($e->getMessage(), self::ERROR_LIMIT));
        }

        if ($response->successful()) {
            $names = [];
            foreach ((array) $response->json('models', []) as $model) {
                if (is_array($model) && is_string($model['name'] ?? null)) {
                    $names[] = Str::after($model['name'], 'models/');
                }
            }

            return $names;
        }

        $message = 'HTTP '.$response->status().': '.self::errorMessage($response);
        throw new GeminiException($message, self::keyRejected($response) ? GeminiException::KEY_INVALID : GeminiException::ERROR);
    }

    /**
     * The generateContent body. Public for the payload tests (prompt privacy).
     *
     * @return array<string, mixed>
     */
    public function payload(GeminiRequest $request): array
    {
        $parts = [['text' => $request->userText]];
        foreach ($request->images as $image) {
            if ($image->label !== null) {
                $parts[] = ['text' => $image->label];
            }
            $part = ['inlineData' => ['mimeType' => $image->mimeType, 'data' => base64_encode($image->data)]];
            if ($this->mediaPerPart && $image->mediaResolution !== null) {
                $part['mediaResolution'] = ['level' => MediaResolution::apiValue($image->mediaResolution, true)];
            }
            $parts[] = $part;
        }

        $body = [
            'systemInstruction' => ['parts' => [['text' => $request->systemInstruction]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
        ];

        $config = [];
        if ($request->responseSchema !== null) {
            $config['responseMimeType'] = 'application/json';
            $config['responseJsonSchema'] = self::servingSchema($request->responseSchema);
        }
        if ($this->sendTemperature && $request->temperature !== null) {
            $config['temperature'] = $request->temperature;
        }
        $highest = MediaResolution::highest(array_map(fn (GeminiImage $i) => $i->mediaResolution, $request->images));
        if (! $this->mediaPerPart && $highest !== null) {
            $config['mediaResolution'] = MediaResolution::apiValue($highest, false);
        }
        // An empty GEMINI_THINKING_LEVEL means a model without thinking levels: never send one.
        $thinking = $this->thinkingLevel === null ? null : ($request->thinkingLevel ?? $this->thinkingLevel);
        if ($thinking !== null) {
            $config['thinkingConfig'] = ['thinkingLevel' => $thinking];
        }
        if ($request->maxOutputTokens !== null) {
            $config['maxOutputTokens'] = $request->maxOutputTokens;
        }
        if ($config !== []) {
            $body['generationConfig'] = $config;
        }

        return $body;
    }

    /**
     * The schema Gemini is asked to follow: the response schema without its
     * size and range bounds (maxItems, minItems, minimum, maximum). Nested
     * array bounds and integer ranges make the constrained decoder too large
     * and Gemini answers 400 "Request contains an invalid argument" (seen on
     * extract_batch, extract_page and answer_key_read, 30 Sep 2026). The
     * gateway still checks every bound against the full schema, so an answer
     * outside them stays invalid output.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function servingSchema(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            if (in_array($key, ['maxItems', 'minItems', 'minimum', 'maximum'], true) && ! is_array($value)) {
                continue;
            }
            $out[$key] = is_array($value) ? self::servingSchema($value) : $value;
        }

        return $out;
    }

    private function reply(mixed $result, int $elapsedMs): GeminiReply
    {
        if ($result instanceof Throwable) {
            return GeminiReply::error(Str::limit(class_basename($result).': '.$result->getMessage(), self::ERROR_LIMIT), $elapsedMs);
        }
        if (! $result instanceof Response) {
            return GeminiReply::error('no response', $elapsedMs);
        }

        $latency = self::latency($result) ?? $elapsedMs;
        if (! $result->successful()) {
            $message = 'HTTP '.$result->status().': '.self::errorMessage($result);

            return self::keyRejected($result)
                ? GeminiReply::keyRejected($message, $latency, $result->status())
                : GeminiReply::error($message, $latency, $result->status());
        }

        $json = $result->json();
        if (! is_array($json)) {
            return GeminiReply::error('the response body is not JSON', $latency, $result->status());
        }
        $usage = (array) ($json['usageMetadata'] ?? []);
        $input = isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null;
        $cached = isset($usage['cachedContentTokenCount']) ? (int) $usage['cachedContentTokenCount'] : null;
        $thinking = isset($usage['thoughtsTokenCount']) ? (int) $usage['thoughtsTokenCount'] : null;
        $output = isset($usage['candidatesTokenCount']) || isset($usage['thoughtsTokenCount'])
            ? (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0)
            : null;

        if (isset($json['promptFeedback']['blockReason'])) {
            return new GeminiReply(GeminiReply::ERROR, null, $input, $output, $latency, 'prompt blocked: '.$json['promptFeedback']['blockReason'], 200);
        }

        // The answer is the text of the non-thought parts of the first candidate;
        // an empty or cut-off answer fails validation as invalid_output.
        $text = '';
        foreach ((array) ($json['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (is_array($part) && ! ($part['thought'] ?? false) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        $finish = $json['candidates'][0]['finishReason'] ?? null;

        return GeminiReply::ok($text, $input, $output, $latency, $cached, $thinking, is_string($finish) ? $finish : null);
    }

    private static function latency(Response $response): ?int
    {
        $total = $response->handlerStats()['total_time'] ?? null;

        return is_numeric($total) ? (int) round((float) $total * 1000) : null;
    }

    /** 401/403, or 400 that names the key: retrying with this key cannot help. */
    private static function keyRejected(Response $response): bool
    {
        if (in_array($response->status(), [401, 403], true)) {
            return true;
        }
        if ($response->status() !== 400) {
            return false;
        }
        $body = $response->body();

        return str_contains($body, 'API_KEY_INVALID') || str_contains($body, 'API key not valid') || str_contains($body, 'API key expired');
    }

    /**
     * error.message, plus the field and reason of a 400's BadRequest details
     * (the bare "Request contains an invalid argument." names no field).
     */
    private static function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');
        if (! is_string($message)) {
            return Str::limit($response->body(), self::ERROR_LIMIT);
        }
        $fields = [];
        foreach ((array) $response->json('error.details', []) as $detail) {
            foreach ((array) ($detail['fieldViolations'] ?? []) as $violation) {
                $fields[] = trim(($violation['field'] ?? '').': '.($violation['description'] ?? ''), ': ');
            }
        }

        return Str::limit($fields === [] ? $message : $message.' ('.implode('; ', $fields).')', self::ERROR_LIMIT);
    }
}
