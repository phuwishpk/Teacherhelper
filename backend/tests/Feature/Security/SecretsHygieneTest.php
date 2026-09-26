<?php

namespace Tests\Feature\Security;

use App\Domain\Notifications\Notifier;
use App\Jobs\GradeScanJob;
use App\Models\AiCall;
use App\Models\Response;
use App\Models\TeacherApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scans\ScanFixtures;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * DESIGN §10.1: a teacher's Gemini key is stored encrypted and shown only as
 * its last four characters. This follows one key through the whole
 * lifecycle (save, status, grading, a rejected key, deletion) and searches
 * every response body, every log line and every ai_calls row for it.
 */
class SecretsHygieneTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    /** Long enough for the request rules; does not look like a real Google key. */
    private const KEY = 'teacher-gemini-key-not-real-ABCDEFGHIJ1234';

    private const SERVER_KEY = 'testing-server-gemini-key-not-real';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
        $this->app->instance(Notifier::class, new RecordingNotifier);
        Log::listen(function ($event) {
            $this->logged[] = $event->level.' '.$event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
    }

    private function assertNoKeyIn(string $haystack, string $where, string $key = self::KEY): void
    {
        $this->assertStringNotContainsString($key, $haystack, "the key leaked into {$where}");
        $this->assertStringNotContainsString(base64_encode($key), $haystack, "the key (base64) leaked into {$where}");
    }

    private function grade(): Response
    {
        $scanId = (int) $this->postScan($this->metaFor(2))->assertStatus(201)->json('scan_id');
        $this->app->call([(new GradeScanJob($scanId))->withFakeQueueInteractions(), 'handle']);

        return Response::query()->where('question_id', $this->work->id)->sole();
    }

    public function test_the_key_is_never_returned_logged_or_stored_in_clear(): void
    {
        config(['services.gemini.api_key' => null]); // grading must use the teacher's key

        $saved = $this->asUser($this->teacher)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => self::KEY])->assertOk();
        $this->assertNoKeyIn((string) $saved->getContent(), 'PUT /me/ai-key');
        $this->assertSame('1234', $saved->json('data.key_last4'));

        $status = $this->asUser($this->teacher)->getJson('/api/v1/me/ai-key')->assertOk();
        $this->assertNoKeyIn((string) $status->getContent(), 'GET /me/ai-key');
        $this->assertSame(['configured' => true, 'key_last4' => '1234'], ['configured' => $status->json('data.configured'), 'key_last4' => $status->json('data.key_last4')]);
        $this->assertNoKeyIn((string) $this->asUser($this->teacher)->getJson('/api/v1/me')->getContent(), 'GET /me');

        $row = (array) DB::table('teacher_api_keys')->where('user_id', $this->teacher->id)->first();
        $this->assertNoKeyIn(json_encode($row), 'the teacher_api_keys row');
        $this->assertNotEmpty($row['encrypted_key']);

        $response = $this->grade();
        $this->assertSame('scored', $response->grading_state, 'the teacher key was used');
        $this->assertGreaterThan(0, AiCall::query()->count());
        foreach (AiCall::query()->get() as $call) {
            $this->assertNoKeyIn(json_encode($call->getAttributes(), JSON_UNESCAPED_UNICODE), "ai_calls #{$call->id}");
        }
        foreach ([
            "/api/v1/responses/{$response->id}",
            "/api/v1/assignments/{$this->assignment->id}/review-queue",
            "/api/v1/scans/{$response->scan_id}/page",
        ] as $uri) {
            $this->assertNoKeyIn((string) $this->asUser($this->teacher)->getJson($uri)->getContent(), $uri);
        }
        $this->assertNoKeyIn(implode("\n", $this->logged), 'the log');
        $this->assertNoKeyIn(json_encode($response->getAttributes(), JSON_UNESCAPED_UNICODE), 'the responses row');

        $this->asUser($this->teacher)->deleteJson('/api/v1/me/ai-key')->assertOk();
        $this->assertDatabaseCount('teacher_api_keys', 0);
    }

    public function test_a_key_google_rejects_leaves_no_trace_in_errors_or_logs(): void
    {
        config(['services.gemini.api_key' => null]);
        // Saved earlier, revoked at Google since: the fake refuses keys containing "rejected".
        $rejected = 'rejected-teacher-key-not-real-0000000000';
        TeacherApiKey::create(['user_id' => $this->teacher->id, 'provider' => TeacherApiKey::PROVIDER_GEMINI, 'encrypted_key' => $rejected, 'key_last4' => substr($rejected, -4), 'last_verified_at' => now()]);

        $response = $this->grade();
        $this->assertSame(['manual', 'ai_key_invalid'], [$response->grading_state, $response->manualReason()]);

        $detail = $this->asUser($this->teacher)->getJson("/api/v1/responses/{$response->id}")->assertOk();
        $this->assertNoKeyIn((string) $detail->getContent(), 'GET /responses/{id}', $rejected);
        foreach (AiCall::query()->get() as $call) {
            $this->assertNoKeyIn(json_encode($call->getAttributes(), JSON_UNESCAPED_UNICODE), "ai_calls #{$call->id}", $rejected);
        }
        $this->assertNoKeyIn(implode("\n", $this->logged), 'the log', $rejected);

        // Verifying a bad key through the API: the 422 does not echo it either.
        $invalid = 'invalid-teacher-key-not-real-1111111111';
        $refused = $this->asUser($this->teacher)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => $invalid])->assertStatus(422);
        $this->assertSame('ai_key_invalid', $refused->json('code'));
        $this->assertNoKeyIn((string) $refused->getContent(), 'PUT /me/ai-key (invalid)', $invalid);
        $this->assertNoKeyIn(implode("\n", $this->logged), 'the log', $invalid);

        // A malformed key fails validation without being echoed back.
        $malformed = 'has spaces and is otherwise long enough to pass min';
        $bad = $this->asUser($this->teacher)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => $malformed])->assertStatus(422);
        $this->assertStringNotContainsString($malformed, (string) $bad->getContent());
    }

    public function test_the_server_key_never_reaches_a_client(): void
    {
        $this->assertSame(self::SERVER_KEY, config('services.gemini.api_key'));
        $response = $this->grade();

        foreach ([
            '/api/v1/me/ai-key',
            '/api/v1/me',
            '/api/v1/health',
            "/api/v1/responses/{$response->id}",
            "/api/v1/assignments/{$this->assignment->id}/review-queue",
            "/api/v1/assignments/{$this->assignment->id}",
        ] as $uri) {
            $this->assertNoKeyIn((string) $this->asUser($this->teacher)->getJson($uri)->getContent(), $uri, self::SERVER_KEY);
        }
        $this->assertNoKeyIn(implode("\n", $this->logged), 'the log', self::SERVER_KEY);
        foreach (AiCall::query()->get() as $call) {
            $this->assertNoKeyIn(json_encode($call->getAttributes(), JSON_UNESCAPED_UNICODE), "ai_calls #{$call->id}", self::SERVER_KEY);
        }
    }
}
