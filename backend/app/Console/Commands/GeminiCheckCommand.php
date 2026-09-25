<?php

namespace App\Console\Commands;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\SchemaValidator;
use Illuminate\Console\Command;

/**
 * Checks the Gemini setup from the server (Plesk: Scheduled Task "Run now";
 * locally: php artisan eduvision:gemini-check). Lists models with the server
 * key (or a teacher's saved key) and, with --generate, sends one tiny
 * structured-output request with the configured model. Prints only the last
 * 4 characters of a key. Nothing is written to ai_calls.
 */
class GeminiCheckCommand extends Command
{
    protected $signature = 'eduvision:gemini-check
        {--teacher= : check the key saved by this teacher (user id) instead of GEMINI_API_KEY}
        {--generate : also send one tiny generateContent request (a few tokens)}';

    protected $description = 'Check the Gemini API key, model and structured output (DESIGN §10.1)';

    public function handle(GeminiClient $client, GeminiKeyResolver $keys): int
    {
        $fake = $client instanceof FakeGeminiClient;
        $this->line('client: '.($fake ? 'FakeGeminiClient (GEMINI_FAKE=true, offline)' : 'HttpGeminiClient'));
        $this->line('model:  '.$client->model());
        if ($fake && ! app()->environment(['local', 'testing'])) {
            $this->warn('GEMINI_FAKE=true outside local/testing: scores are made up from an image hash and any key is accepted. Set GEMINI_FAKE=false.');
        }
        if (! $fake && config('services.gemini.fake')) {
            $this->warn('GEMINI_FAKE=true is ignored in production: the real Gemini API is used. Set GEMINI_FAKE=false in .env.');
        }

        $teacherId = $this->option('teacher');
        $key = $teacherId !== null ? $keys->teacherKey((int) $teacherId) : $keys->serverKey();
        if ($key === null) {
            $this->error($teacherId !== null
                ? "teacher {$teacherId} has no usable saved key"
                : 'GEMINI_API_KEY is empty: grading uses teachers\' own keys only (answers without one go to the teacher as manual)');

            return self::FAILURE;
        }
        $this->line("key:    {$key->source} ••••{$key->last4()}");

        try {
            $models = $client->listModels($key->apiKey);
        } catch (GeminiException $e) {
            $this->error("models.list failed ({$e->status}): {$e->getMessage()}");

            return self::FAILURE;
        }
        $model = (string) config('services.gemini.model');
        $this->info('models.list ok: '.count($models).' models');
        if (! in_array($model, $models, true)) {
            $this->warn("GEMINI_MODEL {$model} is not in the list for this key: check the model id");
        }

        if ($this->option('generate')) {
            return $this->generate($client, $key) ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function generate(GeminiClient $client, GeminiKey $key): bool
    {
        $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean'], 'word_th' => ['type' => 'string']], 'required' => ['ok', 'word_th']];
        $reply = $client->generate(['check' => new GeminiRequest(
            purpose: 'check',
            type: 'general',
            promptVersion: 'v1',
            systemInstruction: 'You are a connectivity check. Answer with the JSON the schema asks for.',
            userText: 'Set ok to true and word_th to the Thai word for "hello".',
            responseSchema: $schema,
            temperature: 0.0,
        )], $key->apiKey)['check'];

        if (! $reply->isOk()) {
            $this->error("generateContent failed: {$reply->error}");

            return false;
        }
        $data = json_decode((string) $reply->text, true);
        if (! is_array($data) || SchemaValidator::validate($schema, $data) !== []) {
            $this->error('generateContent answered, but not with the requested JSON: '.mb_substr((string) $reply->text, 0, 200));

            return false;
        }
        $this->info(sprintf(
            'generateContent ok: %s (%s ms, %s in / %s out tokens)',
            json_encode($data, JSON_UNESCAPED_UNICODE),
            $reply->latencyMs ?? '?',
            $reply->inputTokens ?? '?',
            $reply->outputTokens ?? '?',
        ));

        return true;
    }
}
