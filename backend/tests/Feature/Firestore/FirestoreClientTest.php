<?php

namespace Tests\Feature\Firestore;

use App\Domain\Firestore\FirestoreClient;
use App\Domain\Firestore\FirestoreLiveStore;
use App\Domain\Firestore\FirestoreUnavailable;
use App\Domain\Firestore\FirestoreValues;
use App\Domain\Firestore\LiveStore;
use App\Domain\Firestore\NullLiveStore;
use App\Domain\Notifications\Fcm\AccessTokens;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Firestore REST through the FCM service account (DESIGN §38.4), against
 * Http::fake: the commit body, the datastore scope, the 401 retry, read-back,
 * the LiveStore that never throws, and the container binding.
 */
class FirestoreClientTest extends TestCase
{
    use FirestoreFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeServiceAccountFile();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->removeServiceAccountFile();
        parent::tearDown();
    }

    private function client(): FirestoreClient
    {
        $account = $this->account();

        return new FirestoreClient($account, new AccessTokens($account, 5, FirestoreClient::SCOPE), '(default)', 5);
    }

    public function test_set_sends_one_commit_with_encoded_fields_and_the_datastore_scope(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::COMMIT_URL => Http::response(['writeResults' => [[]]]),
        ]);
        $at = CarbonImmutable::parse('2026-10-21 10:00:00', 'Asia/Bangkok');

        $this->client()->set('users/u12/notices/n1', [
            'title' => 'ตรวจเสร็จ', 'read' => false, 'count' => 3, 'ratio' => 0.5, 'none' => null,
            'data' => ['assignment_id' => 7], 'tags' => ['a', 'b'], 'created_at' => $at,
        ]);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::TOKEN_URI) {
                return false;
            }
            $claims = json_decode(base64_decode(strtr(explode('.', $request['assertion'])[1], '-_', '+/')), true);

            return $claims['scope'] === FirestoreClient::SCOPE;
        });
        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::COMMIT_URL) {
                return false;
            }
            $this->assertSame('Bearer at-1', $request->header('Authorization')[0]);
            $write = $request['writes'][0];
            $this->assertArrayNotHasKey('updateMask', $write);
            $this->assertSame('projects/eduvision-test/databases/(default)/documents/users/u12/notices/n1', $write['update']['name']);
            $this->assertSame([
                'title' => ['stringValue' => 'ตรวจเสร็จ'],
                'read' => ['booleanValue' => false],
                'count' => ['integerValue' => '3'],
                'ratio' => ['doubleValue' => 0.5],
                'none' => ['nullValue' => null],
                'data' => ['mapValue' => ['fields' => ['assignment_id' => ['integerValue' => '7']]]],
                'tags' => ['arrayValue' => ['values' => [['stringValue' => 'a'], ['stringValue' => 'b']]]],
                'created_at' => ['timestampValue' => '2026-10-21T03:00:00.000000Z'],
            ], $write['update']['fields']);

            return true;
        });
    }

    public function test_merge_sets_an_update_mask_and_delete_sends_a_delete_write(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            self::COMMIT_URL => Http::response(['writeResults' => [[], []]]),
        ]);
        $client = $this->client();

        $client->commit([
            $client->setWrite('users/u1/meta/inbox', ['unread' => 2, 'odd-name' => 'x'], merge: true),
            $client->deleteWrite('users/u1/notices/old'),
        ]);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::COMMIT_URL) {
                return false;
            }
            $this->assertSame(['unread', '`odd-name`'], $request['writes'][0]['updateMask']['fieldPaths']);
            $this->assertSame('projects/eduvision-test/databases/(default)/documents/users/u1/notices/old', $request['writes'][1]['delete']);

            return true;
        });
    }

    public function test_a_401_drops_the_cached_token_and_retries_once(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::sequence()
                ->push(['access_token' => 'stale', 'expires_in' => 3600])
                ->push(['access_token' => 'fresh', 'expires_in' => 3600]),
            self::COMMIT_URL => Http::sequence()
                ->push(['error' => ['status' => 'UNAUTHENTICATED']], 401)
                ->push(['writeResults' => [[]]]),
        ]);

        $this->client()->set('users/u1/notices/n1', ['title' => 'x']);

        $tokens = [];
        Http::assertSent(function (Request $request) use (&$tokens) {
            if ($request->url() === self::COMMIT_URL) {
                $tokens[] = $request->header('Authorization')[0];
            }

            return true;
        });
        $this->assertSame(['Bearer stale', 'Bearer fresh'], $tokens);
    }

    public function test_failures_become_firestore_unavailable(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::COMMIT_URL => Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'Missing or insufficient permissions.']], 403),
        ]);
        try {
            $this->client()->set('users/u1/notices/n1', ['title' => 'x']);
            $this->fail('expected FirestoreUnavailable');
        } catch (FirestoreUnavailable $e) {
            $this->assertStringContainsString('HTTP 403 PERMISSION_DENIED', $e->getMessage());
        }

        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::COMMIT_URL => fn () => throw new ConnectionException('timed out'),
        ]);
        Cache::flush();
        $this->expectException(FirestoreUnavailable::class);
        $this->client()->set('users/u1/notices/n1', ['title' => 'x']);
    }

    public function test_a_bad_token_exchange_is_unavailable_too(): void
    {
        Http::fake([self::TOKEN_URI => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->expectException(FirestoreUnavailable::class);
        $this->expectExceptionMessage('auth:');
        $this->client()->set('users/u1/notices/n1', ['title' => 'x']);
    }

    public function test_get_reads_a_document_back_and_null_when_missing(): void
    {
        $docUrl = 'https://firestore.googleapis.com/v1/projects/eduvision-test/databases/(default)/documents/_health/server';
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            $docUrl => Http::sequence()
                ->push(['name' => 'x', 'fields' => [
                    'checked_at' => ['timestampValue' => '2026-10-21T03:00:00Z'],
                    'n' => ['integerValue' => '4'],
                    'list' => ['arrayValue' => ['values' => [['booleanValue' => true]]]],
                    'map' => ['mapValue' => ['fields' => ['k' => ['nullValue' => null]]]],
                ]])
                ->push(['error' => ['status' => 'NOT_FOUND']], 404),
        ]);
        $client = $this->client();

        $this->assertSame([
            'checked_at' => '2026-10-21T03:00:00Z', 'n' => 4, 'list' => [true], 'map' => ['k' => null],
        ], $client->get('_health/server'));
        $this->assertNull($client->get('_health/server'));
    }

    public function test_values_reject_objects_and_commits_over_the_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FirestoreValues::encode(new \stdClass);
    }

    public function test_commit_rejects_more_than_500_writes_and_ignores_an_empty_list(): void
    {
        Http::fake();
        $client = $this->client();
        $client->commit([]);
        Http::assertNothingSent();

        $this->expectException(\InvalidArgumentException::class);
        $client->commit(array_fill(0, 501, $client->deleteWrite('x/y')));
    }

    public function test_live_store_swallows_failures_with_one_log_line(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::COMMIT_URL => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503),
        ]);
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
            $this->assertSame('firestore.write_failed', $message);
            $this->assertSame(['users/u1/notices/n1', 'users/u1/meta/inbox'], $context['paths']);
            $this->assertArrayNotHasKey('fields', $context);

            return true;
        });
        $store = new FirestoreLiveStore($this->client());

        $store->batch([
            ['path' => 'users/u1/notices/n1', 'fields' => ['title' => 'secret-ish']],
            ['path' => 'users/u1/meta/inbox', 'fields' => ['unread' => 1], 'merge' => true],
        ]);
        $this->assertTrue($store->enabled());
    }

    public function test_live_store_set_and_delete_commit(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::COMMIT_URL => Http::response(['writeResults' => [[]]]),
        ]);
        $store = new FirestoreLiveStore($this->client());
        $store->set('users/u1/notices/n1', ['title' => 'x']);
        $store->delete('users/u1/notices/n1');
        Http::assertSentCount(3); // one token exchange + two commits
    }

    public function test_container_binds_null_store_unless_enabled_with_a_usable_key(): void
    {
        $this->enableFirestore(false);
        $this->assertInstanceOf(NullLiveStore::class, app(LiveStore::class));
        $this->assertFalse(app(LiveStore::class)->enabled());

        $this->enableFirestore(true, credentials: '');
        $this->assertInstanceOf(NullLiveStore::class, app(LiveStore::class));

        $this->enableFirestore(true, credentials: '/nonexistent/key.json');
        Log::shouldReceive('error')->once()->withArgs(fn (string $m, array $c) => $m === 'firestore.credentials_invalid' && ! str_contains(json_encode($c), 'PRIVATE'));
        $this->assertInstanceOf(NullLiveStore::class, app(LiveStore::class));

        $this->enableFirestore(true);
        $this->assertInstanceOf(FirestoreLiveStore::class, app(LiveStore::class));

        // The null store does nothing, and that is fine for every writer.
        Http::fake();
        $null = new NullLiveStore;
        $null->set('a/b', ['x' => 1]);
        $null->delete('a/b');
        $null->batch([['path' => 'a/b', 'delete' => true]]);
        Http::assertNothingSent();
    }
}
