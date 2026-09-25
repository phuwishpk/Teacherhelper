<?php

namespace Tests\Feature\Gemini;

use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiImage;
use App\Domain\Gemini\GeminiReply;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\HttpGeminiClient;
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
