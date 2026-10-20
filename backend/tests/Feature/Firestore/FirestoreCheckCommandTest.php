<?php

namespace Tests\Feature\Firestore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** eduvision:firestore-check (DESIGN §38.4) against Http::fake. */
class FirestoreCheckCommandTest extends TestCase
{
    use FirestoreFixtures;
    use RefreshDatabase;

    private const DOC_URL = 'https://firestore.googleapis.com/v1/projects/eduvision-test/databases/(default)/documents/_health/server';

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

    public function test_off_or_without_credentials_fails_with_a_hint(): void
    {
        $this->enableFirestore(false);
        $this->artisan('eduvision:firestore-check')->expectsOutputToContain('FIRESTORE_ENABLED is false')->assertFailed();

        $this->enableFirestore(true, credentials: '');
        $this->artisan('eduvision:firestore-check')->expectsOutputToContain('FIREBASE_CREDENTIALS is empty')->assertFailed();

        $this->enableFirestore(true, credentials: '/nonexistent.json');
        $this->artisan('eduvision:firestore-check')->expectsOutputToContain('file not found')->assertFailed();
    }

    public function test_writes_reads_and_issues_a_token(): void
    {
        $this->enableFirestore(true);
        $this->makeTeacher();
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::COMMIT_URL => Http::response(['writeResults' => [[]]]),
            self::DOC_URL => Http::response(['fields' => ['checked_at' => ['timestampValue' => '2026-10-21T03:00:00Z']]]),
        ]);

        $this->artisan('eduvision:firestore-check')
            ->expectsOutputToContain('project: eduvision-test')
            ->expectsOutputToContain('write ok')
            ->expectsOutputToContain('read ok')
            ->expectsOutputToContain('custom token ok (uid u')
            ->assertSuccessful();
    }

    public function test_a_refused_write_fails_with_the_console_hint(): void
    {
        $this->enableFirestore(true);
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::COMMIT_URL => Http::response(['error' => ['status' => 'NOT_FOUND', 'message' => 'database not found']], 404),
        ]);

        $this->artisan('eduvision:firestore-check')
            ->expectsOutputToContain('HTTP 404 NOT_FOUND')
            ->expectsOutputToContain('Is Firestore created')
            ->assertFailed();
    }
}
