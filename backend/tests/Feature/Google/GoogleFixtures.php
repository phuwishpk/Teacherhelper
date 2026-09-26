<?php

namespace Tests\Feature\Google;

use App\Domain\Google\GoogleScopes;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fakes of Google's OAuth, Classroom v1 and Drive v3 endpoints (DESIGN §18.8):
 * no test reaches Google. The tokens below are fake values whose only job
 * is to be searched for in responses and logs (privacy tests).
 */
trait GoogleFixtures
{
    protected const CLIENT_ID = 'test-web-client.apps.googleusercontent.com';

    protected const REFRESH_TOKEN = '1//refresh-token-that-must-never-leak-0123456789';

    protected const ACCESS_TOKEN = 'ya29.access-token-for-tests-only';

    protected const COURSE_ID = '612000000001';

    protected const COURSE_WORK_ID = '713000000001';

    protected const CLASSROOM = 'https://classroom.googleapis.com/v1';

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    protected array $logged = [];

    protected function configureGoogle(): void
    {
        config([
            'services.google.client_id' => self::CLIENT_ID,
            'services.google.client_secret' => 'test-client-secret-not-real',
        ]);
    }

    /** Records every log line so tests can prove no token is written. */
    protected function captureLogs(): void
    {
        Log::listen(function ($event) {
            $this->logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    protected function assertNoSecretIn(string $haystack, string $where): void
    {
        foreach ([self::REFRESH_TOKEN, self::ACCESS_TOKEN, 'test-client-secret-not-real'] as $secret) {
            $this->assertStringNotContainsString($secret, $haystack, "a token leaked into {$where}");
        }
    }

    protected function assertNoSecretInLogs(): void
    {
        $this->assertNoSecretIn(json_encode($this->logged, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 'the log');
    }

    protected function connectGoogle(User $teacher, array $attributes = []): GoogleAccount
    {
        return GoogleAccount::create([
            'user_id' => $teacher->id,
            'google_sub' => 'google-sub-'.$teacher->id,
            'email' => "teacher{$teacher->id}@school.example",
            'encrypted_refresh_token' => self::REFRESH_TOKEN,
            'scopes' => implode(' ', GoogleScopes::REQUIRED),
            'connected_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function tokenBody(?array $scopes = null, bool $withRefresh = true): array
    {
        return array_filter([
            'access_token' => self::ACCESS_TOKEN,
            'expires_in' => 3599,
            'token_type' => 'Bearer',
            'scope' => implode(' ', $scopes ?? GoogleScopes::REQUIRED),
            'refresh_token' => $withRefresh ? self::REFRESH_TOKEN : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Fakes Google: the token endpoint answers a fresh access token unless
     * $routes says otherwise; $routes come first (first match wins).
     *
     * @param  array<string, mixed>  $routes
     */
    protected function fakeGoogle(array $routes = []): void
    {
        Http::fake($routes + [
            'oauth2.googleapis.com/token' => Http::response(self::tokenBody()),
            'oauth2.googleapis.com/revoke' => Http::response([]),
        ]);
    }

    /** @return array<string, mixed> a Classroom error body */
    protected static function googleError(int $code, string $status, string $message, ?string $reason = null): array
    {
        $error = ['code' => $code, 'message' => $message, 'status' => $status];
        if ($reason !== null) {
            $error['details'] = [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => $reason, 'domain' => 'googleapis.com']];
        }

        return ['error' => $error];
    }

    /**
     * A TURNED_IN StudentSubmission resource.
     *
     * @param  list<array{0: string, 1: string}>  $files  [drive id, title]
     * @return array<string, mixed>
     */
    protected static function turnedIn(string $id, string $userId, array $files, string $updateTime = '2026-09-20T02:15:00.000Z'): array
    {
        return [
            'courseId' => self::COURSE_ID,
            'courseWorkId' => self::COURSE_WORK_ID,
            'id' => $id,
            'userId' => $userId,
            'state' => 'TURNED_IN',
            'updateTime' => $updateTime,
            'alternateLink' => "https://classroom.google.com/c/x/a/y/submissions/student/{$id}",
            'assignmentSubmission' => ['attachments' => array_map(
                fn (array $f) => ['driveFile' => ['id' => $f[0], 'title' => $f[1], 'alternateLink' => 'https://drive.google.com/open?id='.$f[0]]],
                $files,
            )],
        ];
    }

    /** Requests sent to a URL containing $needle. @return list<Request> */
    protected function sentTo(string $needle, ?string $method = null): array
    {
        return array_values(array_map(
            fn (array $pair) => $pair[0],
            Http::recorded(fn (Request $r) => str_contains($r->url(), $needle) && ($method === null || $r->method() === $method))->all(),
        ));
    }
}
