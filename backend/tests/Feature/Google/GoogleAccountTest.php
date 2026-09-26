<?php

namespace Tests\Feature\Google;

use App\Domain\Google\GoogleScopes;
use App\Models\ClassroomGoogleLink;
use App\Models\GoogleAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /google/connect, GET /google/status, DELETE /google/disconnect,
 * GET /google/courses (DESIGN §18.5, §18.6) against faked Google endpoints.
 */
class GoogleAccountTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGoogle();
        $this->captureLogs();
    }

    private function profile(string $id = '1122334455', string $email = 'kru.somsri@school.example'): array
    {
        return ['id' => $id, 'name' => ['fullName' => 'ครูสมศรี'], 'emailAddress' => $email];
    }

    public function test_connect_exchanges_the_code_and_stores_only_the_encrypted_refresh_token(): void
    {
        $teacher = $this->makeTeacher();
        $this->fakeGoogle(['classroom.googleapis.com/v1/userProfiles/me' => Http::response($this->profile())]);

        $res = $this->asUser($teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.email', 'kru.somsri@school.example')
            ->assertJsonPath('data.needs_reconnect', false)
            ->assertJsonPath('data.scopes', GoogleScopes::REQUIRED);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token'
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === '4/0AQlEd8x-one-time-code'
            && $r['client_id'] === self::CLIENT_ID
            && $r['redirect_uri'] === '');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/userProfiles/me') && $r->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN));

        $account = GoogleAccount::query()->findOrFail($teacher->id);
        $this->assertSame(['1122334455', self::REFRESH_TOKEN, null], [$account->google_sub, $account->encrypted_refresh_token, $account->last_error]);
        $raw = DB::table('google_accounts')->where('user_id', $teacher->id)->value('encrypted_refresh_token');
        $this->assertNotSame(self::REFRESH_TOKEN, $raw, 'stored encrypted');
        $this->assertStringNotContainsString(self::REFRESH_TOKEN, (string) $raw);

        $this->assertNoSecretIn($res->getContent(), 'the connect answer');
        $this->assertNoSecretIn($this->asUser($teacher)->getJson('/api/v1/google/status')->getContent(), 'the status answer');
        $this->assertNoSecretIn(json_encode($account->toArray()), 'the serialised model');
        $this->assertNoSecretInLogs();
    }

    public function test_a_missing_scope_is_refused_revoked_and_nothing_is_stored(): void
    {
        $teacher = $this->makeTeacher();
        $granted = array_values(array_diff(GoogleScopes::REQUIRED, [GoogleScopes::PREFIX.'drive.readonly']));
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(self::tokenBody($granted))]);

        $this->asUser($teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'google_scope_missing')
            ->assertJsonPath('errors.scopes', [GoogleScopes::PREFIX.'drive.readonly']);

        $this->assertDatabaseCount('google_accounts', 0);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === self::REFRESH_TOKEN);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'userProfiles'));
        $this->assertNoSecretInLogs();
    }

    public function test_a_used_or_expired_code_is_a_clear_error(): void
    {
        $teacher = $this->makeTeacher();
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400)]);

        $this->asUser($teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'google_code_invalid');
        $this->assertDatabaseCount('google_accounts', 0);
    }

    public function test_a_client_mismatch_and_a_missing_configuration_answer_503(): void
    {
        $teacher = $this->makeTeacher();
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['error' => 'unauthorized_client'], 401)]);
        $this->asUser($teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'google_not_configured');

        config(['services.google.client_secret' => '']);
        $this->asUser($teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'google_not_configured');
        $this->asUser($teacher)->getJson('/api/v1/google/status')
            ->assertOk()
            ->assertJsonPath('data.server_configured', false)
            ->assertJsonPath('data.connected', false);
    }

    public function test_one_google_account_belongs_to_one_teacher(): void
    {
        $school = $this->makeSchool();
        [$a, $b] = [$this->makeTeacher($school), $this->makeTeacher($school)];
        $this->connectGoogle($a, ['google_sub' => '1122334455']);
        $this->fakeGoogle(['classroom.googleapis.com/v1/userProfiles/me' => Http::response($this->profile('1122334455'))]);

        $this->asUser($b)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'google_account_in_use');
        // Revoking would also cut off teacher A (same user + client = same grant).
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/revoke'));
        $this->assertNull(GoogleAccount::query()->find($b->id));
    }

    public function test_reconnecting_without_a_new_refresh_token_keeps_a_working_one_only(): void
    {
        $teacher = $this->makeTeacher();
        $this->connectGoogle($teacher, ['google_sub' => '1122334455', 'encrypted_refresh_token' => 'old-refresh-token-still-good']);
        $this->fakeGoogle([
            'oauth2.googleapis.com/token' => Http::response(self::tokenBody(null, withRefresh: false)),
            'classroom.googleapis.com/v1/userProfiles/me' => Http::response($this->profile('1122334455')),
        ]);

        $this->asUser($teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])->assertOk();
        $this->assertSame('old-refresh-token-still-good', GoogleAccount::query()->findOrFail($teacher->id)->encrypted_refresh_token);

        // The stored token is dead (invalid_grant): without a new one there is nothing to keep.
        GoogleAccount::query()->whereKey($teacher->id)->update(['last_error' => GoogleAccount::ERROR_INVALID_GRANT]);
        $this->asUser($teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'google_refresh_token_missing');
    }

    public function test_a_reconnect_clears_needs_reconnect(): void
    {
        $teacher = $this->makeTeacher();
        $this->connectGoogle($teacher, ['google_sub' => '1122334455', 'last_error' => GoogleAccount::ERROR_INVALID_GRANT]);
        $this->asUser($teacher)->getJson('/api/v1/google/status')->assertJsonPath('data.needs_reconnect', true);

        $this->fakeGoogle(['classroom.googleapis.com/v1/userProfiles/me' => Http::response($this->profile('1122334455'))]);
        $this->asUser($teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])
            ->assertOk()
            ->assertJsonPath('data.needs_reconnect', false);
        $this->assertNull(GoogleAccount::query()->findOrFail($teacher->id)->last_error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/revoke'));
    }

    public function test_switching_to_another_google_account_revokes_the_old_grant(): void
    {
        $teacher = $this->makeTeacher();
        $this->connectGoogle($teacher, ['google_sub' => 'old-account', 'encrypted_refresh_token' => 'old-account-refresh-token']);
        $this->fakeGoogle(['classroom.googleapis.com/v1/userProfiles/me' => Http::response($this->profile('new-account', 'new@school.example'))]);

        $this->asUser($teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@school.example');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/revoke') && $r['token'] === 'old-account-refresh-token');
        $this->assertSame('new-account', GoogleAccount::query()->findOrFail($teacher->id)->google_sub);
    }

    public function test_disconnect_revokes_and_deletes_even_when_google_cannot_be_reached(): void
    {
        $teacher = $this->makeTeacher();
        $this->connectGoogle($teacher);
        $this->fakeGoogle(['oauth2.googleapis.com/revoke' => Http::sequence()->push([])->push('down', 503)]);

        $this->asUser($teacher)->deleteJson('/api/v1/google/disconnect')
            ->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.revoked', true);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/revoke') && $r['token'] === self::REFRESH_TOKEN);
        $this->assertDatabaseCount('google_accounts', 0);

        $this->connectGoogle($teacher);
        $this->asUser($teacher)->deleteJson('/api/v1/google/disconnect')
            ->assertOk()
            ->assertJsonPath('data.revoked', false);
        $this->assertDatabaseCount('google_accounts', 0);

        // Idempotent.
        $this->asUser($teacher)->deleteJson('/api/v1/google/disconnect')->assertOk()->assertJsonPath('data.connected', false);
        $this->assertNoSecretInLogs();
    }

    public function test_courses_are_the_active_ones_the_teacher_teaches_across_pages_with_one_token_refresh(): void
    {
        $teacher = $this->makeTeacher();
        $this->connectGoogle($teacher);
        $classroom = $this->makeClassroom($teacher);
        ClassroomGoogleLink::create(['classroom_id' => $classroom->id, 'course_id' => 'c2', 'course_name' => 'ม.2/1', 'owner_user_id' => $teacher->id, 'linked_at' => now()]);
        $this->fakeGoogle(['classroom.googleapis.com/v1/courses*' => Http::sequence()
            ->push(['courses' => [['id' => 'c1', 'name' => 'คณิต ม.1/1', 'section' => 'ห้อง 1']], 'nextPageToken' => 'p2'])
            ->push(['courses' => [['id' => 'c2', 'name' => 'ม.2/1', 'section' => '']]])
            ->push(['courses' => [['id' => 'c1', 'name' => 'คณิต ม.1/1']]]),
        ]);

        $this->asUser($teacher)->getJson('/api/v1/google/courses')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['course_id' => 'c1', 'name' => 'คณิต ม.1/1', 'section' => 'ห้อง 1', 'linked_classroom_id' => null],
                ['course_id' => 'c2', 'name' => 'ม.2/1', 'section' => null, 'linked_classroom_id' => $classroom->id],
            ]]);
        $this->asUser($teacher)->getJson('/api/v1/google/courses')->assertOk()->assertJsonCount(1, 'data');

        $list = $this->sentTo('/v1/courses?');
        $this->assertStringContainsString('teacherId=me', $list[0]->url());
        $this->assertStringContainsString('courseStates=ACTIVE', $list[0]->url());
        $this->assertStringContainsString('pageToken=p2', $list[1]->url());
        $this->assertCount(1, $this->sentTo('oauth2.googleapis.com/token'), 'the access token is cached');
    }

    public function test_a_revoked_refresh_token_marks_the_account_and_every_call_asks_to_reconnect(): void
    {
        $teacher = $this->makeTeacher();
        $this->connectGoogle($teacher);
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

        $this->asUser($teacher)->getJson('/api/v1/google/courses')
            ->assertStatus(409)
            ->assertJsonPath('code', 'google_reconnect_required');
        $this->assertSame(GoogleAccount::ERROR_INVALID_GRANT, GoogleAccount::query()->findOrFail($teacher->id)->last_error);
        $this->asUser($teacher)->getJson('/api/v1/google/status')
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.needs_reconnect', true)
            ->assertJsonPath('data.last_error', 'invalid_grant');

        // No more calls to Google until the teacher connects again.
        $this->asUser($teacher)->getJson('/api/v1/google/courses')->assertStatus(409)->assertJsonPath('code', 'google_reconnect_required');
        $this->assertCount(1, $this->sentTo('oauth2.googleapis.com/token'));
        $this->assertNoSecretInLogs();
    }

    public function test_a_scope_taken_back_later_asks_to_reconnect(): void
    {
        $teacher = $this->makeTeacher();
        $this->connectGoogle($teacher);
        $this->fakeGoogle(['classroom.googleapis.com/*' => Http::response(self::googleError(403, 'PERMISSION_DENIED', 'Request had insufficient authentication scopes.', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'), 403)]);

        $this->asUser($teacher)->getJson('/api/v1/google/courses')
            ->assertStatus(422)
            ->assertJsonPath('code', 'google_scope_missing');
        $this->asUser($teacher)->getJson('/api/v1/google/status')->assertJsonPath('data.needs_reconnect', true);
    }

    public function test_an_expired_access_token_is_refreshed_once_on_401(): void
    {
        $teacher = $this->makeTeacher();
        $this->connectGoogle($teacher);
        $this->fakeGoogle(['classroom.googleapis.com/v1/courses*' => Http::sequence()
            ->push(self::googleError(401, 'UNAUTHENTICATED', 'Request had invalid authentication credentials.'), 401)
            ->push(['courses' => []]),
        ]);

        $this->asUser($teacher)->getJson('/api/v1/google/courses')->assertOk()->assertExactJson(['data' => []]);
        $this->assertCount(2, $this->sentTo('oauth2.googleapis.com/token'));
    }

    public function test_not_connected_unreachable_and_role_checks(): void
    {
        $teacher = $this->makeTeacher();
        $this->fakeGoogle(['classroom.googleapis.com/*' => fn () => throw new ConnectionException('timeout')]);

        $this->asUser($teacher)->getJson('/api/v1/google/courses')->assertStatus(409)->assertJsonPath('code', 'google_not_connected');

        $this->connectGoogle($teacher);
        $this->asUser($teacher)->getJson('/api/v1/google/courses')->assertStatus(503)->assertJsonPath('code', 'google_unavailable');

        $student = $this->enrollStudent($this->makeClassroom($teacher))['student'];
        $this->asUser($student)->getJson('/api/v1/google/status')->assertForbidden();
        $this->asUser($student)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])->assertForbidden();
        $this->asGuest()->getJson('/api/v1/google/status')->assertUnauthorized();

        $this->asUser($teacher)->postJson('/api/v1/google/connect', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }
}
