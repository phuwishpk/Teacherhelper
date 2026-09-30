<?php

namespace Tests\Feature\Gemini;

use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\GeminiCall;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiImage;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiReply;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\HttpGeminiClient;
use App\Domain\Gemini\MediaResolution;
use App\Jobs\GradeScanJob;
use App\Models\AiCall;
use App\Models\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scans\ScanFixtures;
use Tests\TestCase;

/**
 * The real transport (DESIGN §10.1) against Http::fake(): endpoint, header,
 * generateContent body, reply parsing, error mapping, models.list, and a
 * whole GradeScanJob run through it.
 */
class HttpGeminiClientTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    private const KEY = 'TESTSyHttpClientTestKey00000000000000k3y';

    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent';

    private function client(bool $temperature = false, ?string $thinking = 'low'): HttpGeminiClient
    {
        return new HttpGeminiClient('gemini-3.8-flash', 'https://generativelanguage.googleapis.com/v1beta', 30, 8, $thinking, $temperature);
    }

    private function request(string $text = 'hello', array $images = []): GeminiRequest
    {
        return new GeminiRequest(
            purpose: 'extract',
            type: 'short',
            promptVersion: 'v1',
            systemInstruction: 'SYSTEM',
            userText: $text,
            images: $images,
            responseSchema: ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']],
            temperature: 0.0,
            hints: ['question_text' => 'never sent'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(string $text, array $usage = ['promptTokenCount' => 120, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 12]): array
    {
        return [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['text' => 'thinking...', 'thought' => true],
                ['text' => $text],
            ]], 'finishReason' => 'STOP']],
            'usageMetadata' => $usage,
        ];
    }

    public function test_generate_content_request_and_reply(): void
    {
        Http::fake([self::URL => Http::response(self::answer('{"ok":true}'))]);

        $reply = $this->client()->generate(['a' => $this->request('question text', [new GeminiImage('RAWBYTES')])], self::KEY)['a'];

        $this->assertTrue($reply->isOk());
        $this->assertSame('{"ok":true}', $reply->text, 'thought parts are skipped');
        $this->assertSame([120, 42], [$reply->inputTokens, $reply->outputTokens], 'thinking tokens are billed as output');
        $this->assertNotNull($reply->latencyMs);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === self::URL
                && $request->header('x-goog-api-key') === [self::KEY]
                && ! str_contains($request->url(), self::KEY)
                && $body['systemInstruction'] === ['parts' => [['text' => 'SYSTEM']]]
                && $body['contents'] === [['role' => 'user', 'parts' => [
                    ['text' => 'question text'],
                    ['inlineData' => ['mimeType' => 'image/webp', 'data' => base64_encode('RAWBYTES')]],
                ]]]
                && $body['generationConfig']['responseMimeType'] === 'application/json'
                && $body['generationConfig']['responseJsonSchema']['required'] === ['ok']
                && $body['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'low']
                && ! array_key_exists('temperature', $body['generationConfig'])
                && ! str_contains(json_encode($body), 'never sent');
        });
    }

    public function test_temperature_is_sent_only_when_enabled(): void
    {
        $payload = $this->client(temperature: true, thinking: null)->payload($this->request());

        $this->assertSame(0.0, $payload['generationConfig']['temperature']);
        $this->assertArrayNotHasKey('thinkingConfig', $payload['generationConfig']);
    }

    public function test_a_request_s_own_thinking_level_and_output_limit(): void
    {
        $request = new GeminiRequest('answer_key_read', 'general', 'v1', 'SYSTEM', 'hello', thinkingLevel: 'medium', maxOutputTokens: 16384);

        $payload = $this->client()->payload($request);
        $this->assertSame(['thinkingLevel' => 'medium'], $payload['generationConfig']['thinkingConfig']);
        $this->assertSame(16384, $payload['generationConfig']['maxOutputTokens']);

        // A model without thinking levels (GEMINI_THINKING_LEVEL empty) never gets one.
        $this->assertArrayNotHasKey('thinkingConfig', $this->client(thinking: null)->payload($request)['generationConfig']);
        $this->assertArrayNotHasKey('maxOutputTokens', $this->client()->payload($this->request())['generationConfig']);
    }

    public function test_media_resolution_goes_once_per_call_at_the_highest_level_by_default(): void
    {
        // DESIGN §21.5 fallback: one generationConfig.mediaResolution at the highest level of the parts.
        $payload = $this->client()->payload($this->request('batch', [
            new GeminiImage('CROP', GeminiImage::WEBP, MediaResolution::LOW, 'Q2: answer box'),
            new GeminiImage('WORK', GeminiImage::WEBP, MediaResolution::MEDIUM, 'Q3: working area'),
        ]));

        $this->assertSame('MEDIA_RESOLUTION_MEDIUM', $payload['generationConfig']['mediaResolution']);
        $this->assertSame([
            ['text' => 'batch'],
            ['text' => 'Q2: answer box'],
            ['inlineData' => ['mimeType' => 'image/webp', 'data' => base64_encode('CROP')]],
            ['text' => 'Q3: working area'],
            ['inlineData' => ['mimeType' => 'image/webp', 'data' => base64_encode('WORK')]],
        ], $payload['contents'][0]['parts'], 'each label is a text part right before its image');

        $plain = $this->client()->payload($this->request('no level', [new GeminiImage('X')]));
        $this->assertArrayNotHasKey('mediaResolution', $plain['generationConfig']);
    }

    public function test_media_resolution_per_part_when_enabled(): void
    {
        $client = new HttpGeminiClient('gemini-3.8-flash', 'https://generativelanguage.googleapis.com/v1alpha', 30, 8, 'low', false, mediaPerPart: true);
        $payload = $client->payload($this->request('page', [
            new GeminiImage('PAGE', 'application/pdf', MediaResolution::HIGH),
            new GeminiImage('BOX', GeminiImage::WEBP, MediaResolution::LOW),
        ]));

        $this->assertArrayNotHasKey('mediaResolution', $payload['generationConfig']);
        $this->assertSame(['level' => 'MEDIA_RESOLUTION_HIGH'], $payload['contents'][0]['parts'][1]['mediaResolution']);
        $this->assertSame(['level' => 'MEDIA_RESOLUTION_LOW'], $payload['contents'][0]['parts'][2]['mediaResolution']);
    }

    public function test_the_configured_levels_and_the_token_details_of_a_reply(): void
    {
        $this->assertSame('high', MediaResolution::forPart(MediaResolution::PART_SHORT), 'high until calibrated');
        $this->assertSame('medium', MediaResolution::forPart(MediaResolution::PART_DOCUMENT));
        config(['services.gemini.media.short' => 'low', 'services.gemini.media.work' => 'nonsense']);
        $this->assertSame('low', MediaResolution::forPart(MediaResolution::PART_SHORT));
        $this->assertSame('high', MediaResolution::forPart(MediaResolution::PART_WORK), 'an unknown value falls back to the default');
        $this->assertSame('mixed', MediaResolution::summary(['low', 'high']));
        $this->assertNull(MediaResolution::summary([null]));

        Http::fake([self::URL => Http::response(self::answer('{"ok":true}', ['promptTokenCount' => 900, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 12, 'cachedContentTokenCount' => 512]))]);
        $reply = $this->client()->generate(['a' => $this->request()], self::KEY)['a'];
        $this->assertSame([900, 42, 512, 12], [$reply->inputTokens, $reply->outputTokens, $reply->cachedTokens, $reply->thinkingTokens]);
    }

    public function test_a_batch_runs_through_the_pool_with_one_reply_per_request(): void
    {
        Http::fake([self::URL => Http::response(self::answer('{"ok":true}'))]);
        $requests = [];
        foreach (range(1, 10) as $i) {
            $requests[$i] = $this->request("q{$i}");
        }

        $replies = $this->client()->generate($requests, self::KEY);

        $this->assertSame(range(1, 10), array_keys($replies));
        $this->assertContainsOnlyInstancesOf(GeminiReply::class, $replies);
        Http::assertSentCount(10);
    }

    public function test_errors_are_mapped_per_request(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT', 'details' => [['reason' => 'API_KEY_INVALID']]]], 400)
            ->push(['error' => ['code' => 503, 'message' => 'The model is overloaded.']], 503)
            ->push(['error' => ['code' => 403, 'message' => 'Generative Language API has not been used in project']], 403)
            ->push(['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 50]])
            ->push('<html>bad gateway</html>', 200)
            ->push(['error' => ['message' => 'Invalid JSON payload']], 400),
        ]);

        $replies = $this->client()->generate(array_map(fn ($i) => $this->request("q{$i}"), range(0, 5)), self::KEY);

        $this->assertSame(GeminiReply::KEY_REJECTED, $replies[0]->status);
        $this->assertSame([GeminiReply::ERROR, 503], [$replies[1]->status, $replies[1]->httpStatus]);
        $this->assertStringContainsString('overloaded', $replies[1]->error);
        $this->assertSame(GeminiReply::KEY_REJECTED, $replies[2]->status);
        $this->assertSame([GeminiReply::ERROR, 'prompt blocked: SAFETY', 50], [$replies[3]->status, $replies[3]->error, $replies[3]->inputTokens]);
        $this->assertSame(GeminiReply::ERROR, $replies[4]->status);
        $this->assertSame(GeminiReply::ERROR, $replies[5]->status, 'a 400 that is not about the key is an ordinary error');
        foreach ($replies as $reply) {
            $this->assertStringNotContainsString(self::KEY, (string) $reply->error);
        }
    }

    public function test_an_answer_cut_off_at_the_output_cap_is_invalid_output(): void
    {
        $cut = self::answer('{"ok":');
        $cut['candidates'][0]['finishReason'] = 'MAX_TOKENS';
        Http::fake([self::URL => Http::response($cut)]);
        $this->app->instance(GeminiClient::class, $this->client());

        $reply = $this->client()->generate(['a' => $this->request()], self::KEY)['a'];
        $this->assertSame([GeminiReply::OK, 'MAX_TOKENS'], [$reply->status, $reply->finishReason]);

        $outcome = app(GeminiGateway::class)->run(['a' => new GeminiCall($this->request())], new GeminiKey(self::KEY, 'server'))['a'];
        $this->assertSame(CallOutcome::INVALID_OUTPUT, $outcome->status);
        // Retried once like any invalid output, both logged with the reason (DESIGN §21.6).
        $calls = AiCall::query()->get();
        $this->assertSame(['invalid_output', 'invalid_output'], $calls->pluck('status')->all());
        $this->assertStringContainsString('MAX_TOKENS', (string) $calls[0]->error);
    }

    public function test_the_task_s_thinking_level_and_output_cap_go_in_the_generation_config(): void
    {
        $request = new GeminiRequest('explanation', 'general', 'v3', 'S', 'U', thinkingLevel: 'medium', maxOutputTokens: 512);
        $config = $this->client()->payload($request)['generationConfig'];
        $this->assertSame([['thinkingLevel' => 'medium'], 512], [$config['thinkingConfig'], $config['maxOutputTokens']]);
        $this->assertArrayNotHasKey('thinkingConfig', $this->client(thinking: null)->payload($request)['generationConfig'], 'a model without thinking levels');
    }

    public function test_gemini_gets_the_schema_without_bounds_and_the_gateway_still_checks_them(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'answers' => ['type' => 'array', 'maxItems' => 2, 'minItems' => 1, 'items' => ['type' => 'object',
                'properties' => ['question_no' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5], 'maximum' => ['type' => 'string']],
                'required' => ['question_no']]],
        ], 'required' => ['answers']];
        $sent = $this->client()->payload(new GeminiRequest('extract_page', 'general', 'v2', 'S', 'U', responseSchema: $schema))['generationConfig']['responseJsonSchema'];

        $this->assertSame(['type' => 'object', 'properties' => [
            'answers' => ['type' => 'array', 'items' => ['type' => 'object',
                'properties' => ['question_no' => ['type' => 'integer'], 'maximum' => ['type' => 'string']],
                'required' => ['question_no']]],
        ], 'required' => ['answers']], $sent, 'a property that happens to be called "maximum" stays');

        Http::fake([self::URL => Http::response(self::answer('{"answers":[{"question_no":9}]}'))]);
        $this->app->instance(GeminiClient::class, $this->client());
        $request = new GeminiRequest('extract_page', 'general', 'v2', 'S', 'U', responseSchema: $schema);
        $outcome = app(GeminiGateway::class)->run(['a' => new GeminiCall($request)], new GeminiKey(self::KEY, 'server'))['a'];
        $this->assertSame(CallOutcome::INVALID_OUTPUT, $outcome->status, 'question_no 9 > maximum 5');
    }

    public function test_a_bad_request_names_the_offending_field(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['code' => 400, 'message' => 'Request contains an invalid argument.', 'status' => 'INVALID_ARGUMENT',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => 'generation_config.media_resolution', 'description' => 'not supported']]]]]], 400)]);

        $reply = $this->client()->generate(['a' => $this->request()], self::KEY)['a'];
        $this->assertSame('HTTP 400: Request contains an invalid argument. (generation_config.media_resolution: not supported)', $reply->error);
    }

    public function test_a_timeout_is_an_error_not_an_exception(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds')]);

        $reply = $this->client()->generate(['x' => $this->request()], self::KEY)['x'];

        $this->assertSame(GeminiReply::ERROR, $reply->status);
        $this->assertStringContainsString('timed out', $reply->error);
    }

    public function test_list_models(): void
    {
        Http::fake(['https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000' => Http::response(['models' => [
            ['name' => 'models/gemini-3.8-flash'], ['name' => 'models/gemini-3.5-flash-lite'],
        ]])]);

        $this->assertSame(['gemini-3.8-flash', 'gemini-3.5-flash-lite'], $this->client()->listModels(self::KEY));
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->header('x-goog-api-key') === [self::KEY]);
    }

    public function test_list_models_errors(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'details' => [['reason' => 'API_KEY_INVALID']]]], 400)
            ->push(['error' => ['code' => 500, 'message' => 'internal']], 500)]);

        foreach ([GeminiException::KEY_INVALID, GeminiException::ERROR] as $expected) {
            try {
                $this->client()->listModels(self::KEY);
                $this->fail('expected a GeminiException');
            } catch (GeminiException $e) {
                $this->assertSame($expected, $e->status);
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }
    }

    public function test_a_whole_grading_run_through_the_real_client(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
        config(['services.gemini.api_key' => self::KEY]);
        $this->app->instance(GeminiClient::class, $this->client());

        Http::fake([self::URL => Http::sequence()
            ->push(self::answer(json_encode([
                'blank' => false, 'suspicious_instruction' => false, 'legibility' => 'readable',
                'answer_text' => '21', 'key_match' => 'different', 'error_types' => ['calculation'], 'summary_th' => 'บวกผิด',
            ], JSON_UNESCAPED_UNICODE)))
            ->push(self::answer(json_encode(['explanation_th' => 'ตั้งโจทย์ถูกแล้ว แต่บวกเลขพลาด', 'next_step_th' => 'ลองฝึกบวกทศนิยม'], JSON_UNESCAPED_UNICODE)))]);

        $scanId = (int) $this->postScan($this->metaFor(1))->assertStatus(201)->json('scan_id');
        $this->app->call([new GradeScanJob($scanId), 'handle']);

        $short = Response::query()->where('question_id', $this->short->id)->sole();
        // 21 vs key 20: rel_err 0.05 -> M = 0.6 * 0.5 = 0.3 -> fully "mismatch" -> 0 points.
        $this->assertSame(['scored', 0.0, 'not_yet'], [$short->grading_state, $short->ai_score, $short->ai_understanding]);
        $this->assertSame("ตั้งโจทย์ถูกแล้ว แต่บวกเลขพลาด\n\nลองฝึกบวกทศนิยม", $short->explanation);
        $this->assertSame([['extract', 120, 42], ['explanation', 120, 42]], AiCall::query()->orderBy('id')->get()->map(fn ($c) => [$c->purpose, $c->input_tokens, $c->output_tokens])->all());
        $this->assertSame('gemini-3.8-flash', AiCall::query()->value('model'));

        // Prompt privacy on the wire: crops and the teacher's text only.
        $crop = base64_encode($this->fixtureBytes('crop.webp'));
        $page = base64_encode($this->fixtureBytes('page.webp'));
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($this->student->name, $body);
            $this->assertStringNotContainsString($this->classroom->name, $body);
            $this->assertStringNotContainsString($this->qr(1), $body);
            $this->assertStringNotContainsString($page, $body, 'never the whole page');
            $this->assertStringNotContainsString(self::KEY, $body);
        }
        [$extract] = Http::recorded()[0];
        $this->assertSame($crop, $extract->data()['contents'][0]['parts'][1]['inlineData']['data']);
        [$explain] = Http::recorded()[1];
        $this->assertCount(1, $explain->data()['contents'][0]['parts'], 'the explanation request carries no image');
    }
}
