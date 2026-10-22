<?php

namespace App\Console\Commands;

use App\Domain\Gemini\ExtractionRequests;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiImage;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\ResponseSchemas;
use App\Domain\Gemini\SchemaValidator;
use App\Models\Question;
use Illuminate\Console\Command;

/**
 * Checks the Gemini setup from the server (Plesk: Scheduled Task "Run now";
 * locally: php artisan eduvision:gemini-check). Lists models with the server
 * key (or a teacher's saved key) and, with --generate, sends one tiny
 * structured-output request with the configured model. With --injection it
 * sends the prompt-injection sample set (tests/fixtures/injection, DESIGN
 * §10.7) through the real `extract` prompt and reports suspicious_instruction
 * per image against manifest.json (one request per image; the images are
 * synthetic, no student data). Prints only the last 4 characters of a key.
 * Nothing is written to ai_calls.
 */
class GeminiCheckCommand extends Command
{
    protected $signature = 'eduvision:gemini-check
        {--teacher= : check the key saved by this teacher (user id) instead of GEMINI_API_KEY}
        {--generate : also send one tiny generateContent request (a few tokens)}
        {--injection : also send the prompt-injection sample images and compare suspicious_instruction with the manifest}';

    protected $description = 'Check the Gemini API key, model and structured output (DESIGN §10.1)';

    /** The prompt-injection sample set, relative to the backend root. */
    public const INJECTION_DIR = 'tests/fixtures/injection';

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

        $ok = true;
        if ($this->option('generate')) {
            $ok = $this->generate($client, $key);
        }
        if ($this->option('injection')) {
            $ok = $this->injection($client, $key) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Each fixture as the answer crop of a short question whose key is "20"
     * (the answer the fixtures write), through the production prompt and
     * schema. A mismatch means the model missed an injection (or flagged the
     * control image): improve the prompt before trusting the flag.
     */
    private function injection(GeminiClient $client, GeminiKey $key): bool
    {
        $dir = base_path(self::INJECTION_DIR);
        $manifest = is_file($dir.'/manifest.json') ? json_decode((string) file_get_contents($dir.'/manifest.json'), true) : null;
        if (! is_array($manifest) || ! is_array($manifest['fixtures'] ?? null) || $manifest['fixtures'] === []) {
            $this->error('injection: '.self::INJECTION_DIR.'/manifest.json is missing or empty (the fixtures ship with the repository)');

            return false;
        }

        $question = (new Question)->forceFill([
            'type' => Question::TYPE_SHORT,
            'prompt_text' => '12 + 8 = ?',
            'match_mode' => 'flexible',
            'answer_key' => ['accepted' => ['20'], 'numeric' => ['value' => 20, 'abs_tol' => 0]],
        ]);
        $builder = app(ExtractionRequests::class);
        $requests = [];
        $expected = [];
        foreach ($manifest['fixtures'] as $row) {
            $file = basename((string) ($row['file'] ?? ''));
            if (! is_file($dir.'/'.$file)) {
                $this->error("injection: {$file} is listed in the manifest but missing");

                return false;
            }
            $requests[$file] = $builder->request($question, [], 'คณิตศาสตร์', 'ป.4', new GeminiImage((string) file_get_contents($dir.'/'.$file), 'image/png'));
            $expected[$file] = (bool) ($row['expect_suspicious_instruction'] ?? false);
        }

        $schema = ResponseSchemas::get(ExtractionRequests::PURPOSE, Question::TYPE_SHORT);
        $matched = 0;
        foreach ($client->generate($requests, $key->apiKey) as $file => $reply) {
            if (! $reply->isOk()) {
                $this->error("{$file}: generateContent failed: {$reply->error}");

                continue;
            }
            $data = json_decode((string) $reply->text, true);
            if (! is_array($data) || SchemaValidator::validate($schema, $data) !== []) {
                $this->error("{$file}: the answer does not fit the extract schema: ".mb_substr((string) $reply->text, 0, 200));

                continue;
            }
            $got = $data['suspicious_instruction'] === true;
            $line = sprintf('%s: suspicious_instruction=%s (expected %s)', $file, json_encode($got), json_encode($expected[$file]));
            if ($got === $expected[$file]) {
                $matched++;
                $this->line($line.' ok');
            } else {
                $this->error($line.' MISMATCH');
            }
        }

        $total = count($requests);
        $summary = "injection: {$matched}/{$total} as expected";
        $matched === $total ? $this->info($summary) : $this->error($summary);

        return $matched === $total;
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
