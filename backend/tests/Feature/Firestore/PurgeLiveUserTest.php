<?php

namespace Tests\Feature\Firestore;

use App\Domain\Accounts\AccountDeletion;
use App\Domain\Firestore\FirebaseAuthUsers;
use App\Domain\Firestore\FirestoreUnavailable;
use App\Jobs\PurgeLiveUserJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Deleting an account clears its live-layer copy (DESIGN §38.8): every
 * document under users/{uid} and the Firebase Auth user, from the queue.
 */
class PurgeLiveUserTest extends TestCase
{
    use FirestoreFixtures;
    use RefreshDatabase;

    private const DOCS = 'https://firestore.googleapis.com/v1/projects/eduvision-test/databases/(default)/documents';

    private const AUTH_DELETE = 'https://identitytoolkit.googleapis.com/v1/projects/eduvision-test/accounts:delete';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeServiceAccountFile();
        $this->enableFirestore(true);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->removeServiceAccountFile();
        parent::tearDown();
    }

    /** @return list<array{name: string}> */
    private function docs(int $userId, int $count, int $from = 0): array
    {
        return array_map(
            fn (int $i) => ['name' => 'projects/eduvision-test/databases/(default)/documents/users/u'.$userId.'/notices/n'.$i],
            range($from, $from + $count - 1),
        );
    }

    public function test_deleting_an_account_queues_the_purge_only_when_firestore_is_on(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();
        $teacher = $this->makeTeacher();

        app(AccountDeletion::class)->delete($admin, $teacher);
        Queue::assertPushed(PurgeLiveUserJob::class, fn (PurgeLiveUserJob $job) => $job->userId === $teacher->id);

        $this->enableFirestore(false);
        $other = $this->makeTeacher();
        app(AccountDeletion::class)->delete($admin, $other);
        Queue::assertPushed(PurgeLiveUserJob::class, 1);
    }

    public function test_the_job_deletes_every_document_and_the_firebase_user(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::DOCS.'/users/u5:listCollectionIds' => Http::response(['collectionIds' => ['notices']]),
            self::DOCS.'/users/u5/notices*' => Http::sequence()
                ->push(['documents' => $this->docs(5, 500)])
                ->push(['documents' => $this->docs(5, 3, 500)])
                ->push([]),
            self::COMMIT_URL => Http::response(['writeResults' => []]),
            self::AUTH_DELETE => Http::response(['kind' => 'identitytoolkit#DeleteAccountResponse']),
        ]);

        (new PurgeLiveUserJob(5))->handle();

        $commits = Http::recorded(fn (Request $r) => $r->url() === self::COMMIT_URL)->values();
        $this->assertCount(2, $commits, 'a full page of 500, then the 3 left');
        $this->assertCount(500, $commits[0][0]['writes']);
        $this->assertSame(
            'projects/eduvision-test/databases/(default)/documents/users/u5/notices/n502',
            $commits[1][0]['writes'][2]['delete'],
        );
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::DOCS.'/users/u5/notices?')
            && str_contains($r->url(), 'pageSize=500'));
        Http::assertSent(fn (Request $r) => $r->url() === self::AUTH_DELETE && $r['localId'] === 'u5');
        Http::assertSent(function (Request $r) {
            if ($r->url() !== self::TOKEN_URI) {
                return false;
            }
            $claims = json_decode(base64_decode(strtr(explode('.', $r['assertion'])[1], '-_', '+/')), true);

            return $claims['scope'] === FirebaseAuthUsers::SCOPE;
        });
    }

    public function test_a_user_that_never_signed_in_to_firebase_is_fine(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::DOCS.'/users/u6:listCollectionIds' => Http::response([]),
            self::AUTH_DELETE => Http::response(['error' => ['code' => 400, 'message' => 'USER_NOT_FOUND']], 400),
        ]);

        (new PurgeLiveUserJob(6))->handle();

        Http::assertNotSent(fn (Request $r) => $r->url() === self::COMMIT_URL);
    }

    public function test_a_google_failure_throws_so_the_queue_retries(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::DOCS.'/users/u7:listCollectionIds' => Http::response([]),
            self::AUTH_DELETE => Http::response(['error' => ['code' => 403, 'message' => 'PERMISSION_DENIED']], 403),
        ]);

        $this->expectException(FirestoreUnavailable::class);
        $this->expectExceptionMessage('HTTP 403 PERMISSION_DENIED');
        (new PurgeLiveUserJob(7))->handle();
    }

    public function test_the_job_does_nothing_once_firestore_is_off(): void
    {
        $this->enableFirestore(false);
        Http::fake();
        (new PurgeLiveUserJob(8))->handle();
        Http::assertNothingSent();
    }
}
