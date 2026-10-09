<?php

namespace Tests\Feature\Google;

use App\Domain\Google\GoogleOAuth;
use App\Domain\Google\GoogleOAuthStates;
use App\Domain\Google\GoogleScopes;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The browser connect flow (DESIGN §18.5, added for Flutter web and builds
 * without GOOGLE_SERVER_CLIENT_ID): POST /api/v1/google/oauth/url gives
 * Google's consent page with a single-use state, and Google sends the
 * browser back to GET /google/oauth/callback, which stores the account like
 * POST /google/connect and answers a Thai HTML page. Google is faked.
 */
class GoogleOAuthBrowserFlowTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private const REDIRECT_URI = 'https://teacherhelper.test/google/oauth/callback';

    private const CODE = '4/0AVGzR1browser-one-time-code';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGoogle();
        config(['services.google.redirect_uri' => self::REDIRECT_URI]);
        $this->captureLogs();
    }

    /** @return array<string, string> the query of the consent URL the teacher gets */
    private function consentQuery(User $teacher): array
    {
        $url = (string) $this->asUser($teacher)->postJson('/api/v1/google/oauth/url')->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function stateFor(User $teacher): string
    {
        return $this->consentQuery($teacher)['state'];
    }

    /** @param  array<string, string>  $query */
    private function visitCallback(array $query): TestResponse
    {
        $this->asGuest();

        return $this->get('/google/oauth/callback?'.http_build_query($query));
    }

    private function profile(): array
    {
        return ['id' => '1122334455', 'name' => ['fullName' => 'ครูสมศรี'], 'emailAddress' => 'kru.somsri@school.example'];
    }

    private function assertResultPage(TestResponse $res, int $status): void
    {
        $res->assertStatus($status);
        $this->assertStringStartsWith('text/html', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('noindex', (string) $res->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", (string) $res->headers->get('Content-Security-Policy'));
        $this->assertEmpty($res->headers->getCookies(), 'the page sets no cookie (no session)');
        $res->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        // Self-contained: no external stylesheet, script, image or font.
        $this->assertDoesNotMatchRegularExpression('/<(script|link|img)\b|src=|@import|url\(/i', (string) $res->getContent());
        $this->assertNoSecretIn((string) $res->getContent(), 'the callback page');
        $res->assertDontSee(self::CODE, false);
    }

    public function test_the_url_is_googles_consent_page_with_a_stored_single_use_state(): void
    {
        $teacher = $this->makeTeacher();
        Http::fake();

        $res = $this->asUser($teacher)->postJson('/api/v1/google/oauth/url')->assertOk();
        $this->assertSame(['data'], array_keys($res->json()));
        $this->assertSame(['url'], array_keys($res->json('data')));

        $url = (string) $res->json('data.url');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringNotContainsString('+', $url, 'spaces in scope are %20');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = $query['state'];
        unset($query['state']);
        $this->assertSame([
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => implode(' ', GoogleScopes::REQUIRED),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ], $query);

        // 32 random bytes, base64url without padding.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $state);
        $this->assertSame($teacher->id, Cache::store('database')->get(GoogleOAuthStates::key($state)));
        $row = DB::table('cache')->where('key', 'like', '%'.GoogleOAuthStates::key($state))->first();
        $this->assertNotNull($row, 'kept in the database cache store');
        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), (int) $row->expiration, 5);
        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%'.$state.'%')->orWhere('value', 'like', '%'.$state.'%')->count(), 'only a hash of the state is stored');

        $this->assertNotSame($state, $this->stateFor($teacher), 'a new state every time');
        $this->assertNoSecretIn((string) $res->getContent(), 'the url answer');
        Http::assertNothingSent();
    }

    public function test_the_callback_stores_the_account_and_spends_the_state(): void
    {
        $teacher = $this->makeTeacher(null, ['name' => 'ครูสมศรี ใจดี']);
        $this->fakeGoogle(['classroom.googleapis.com/v1/userProfiles/me' => Http::response($this->profile())]);
        $state = $this->stateFor($teacher);

        $res = $this->visitCallback(['state' => $state, 'code' => self::CODE, 'scope' => implode(' ', GoogleScopes::REQUIRED)]);

        $this->assertResultPage($res, 200);
        $res->assertSee('เชื่อม Google Classroom สำเร็จ')
            ->assertSee('kru.somsri@school.example')
            ->assertSee('ครูสมศรี ใจดี')
            ->assertSee('กลับไปที่แอป Krucheck')
            ->assertDontSee($state, false);

        Http::assertSent(fn (Request $r) => $r->url() === GoogleOAuth::TOKEN_URL
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === self::CODE
            && $r['redirect_uri'] === self::REDIRECT_URI
            && $r['client_id'] === self::CLIENT_ID);

        $account = GoogleAccount::query()->findOrFail($teacher->id);
        $this->assertSame(['1122334455', 'kru.somsri@school.example', self::REFRESH_TOKEN], [$account->google_sub, $account->email, $account->encrypted_refresh_token]);
        $this->assertNotSame(self::REFRESH_TOKEN, DB::table('google_accounts')->where('user_id', $teacher->id)->value('encrypted_refresh_token'));
        $this->asUser($teacher)->getJson('/api/v1/google/status')
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.needs_reconnect', false)
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.server_configured', true)
            ->assertJsonPath('data.email', 'kru.somsri@school.example');

        // The state is spent: the same link again is refused before Google.
        $this->assertResultPage($this->visitCallback(['state' => $state, 'code' => self::CODE]), 400);
        $this->assertCount(1, $this->sentTo('oauth2.googleapis.com/token'));
        $this->assertNull(Cache::store('database')->get(GoogleOAuthStates::key($state)));

        $this->assertNoSecretInLogs();
        $log = json_encode($this->logged, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->assertStringNotContainsString(self::CODE, (string) $log);
        $this->assertStringNotContainsString($state, (string) $log);
    }

    public function test_an_unknown_missing_malformed_or_expired_state_is_refused_before_google(): void
    {
        $teacher = $this->makeTeacher();
        $this->fakeGoogle();

        $this->assertResultPage($this->visitCallback(['code' => self::CODE]), 400);
        $this->assertResultPage($this->visitCallback(['state' => 'short', 'code' => self::CODE]), 400);
        $unknown = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->visitCallback(['state' => $unknown, 'code' => self::CODE])
            ->assertStatus(400)
            ->assertSee('ลิงก์เชื่อม Google ใช้ไม่ได้แล้ว');

        $state = $this->stateFor($teacher);
        $this->travel(11)->minutes();
        $this->visitCallback(['state' => $state, 'code' => self::CODE])
            ->assertStatus(400)
            ->assertSee('10 นาที');

        Http::assertNothingSent();
        $this->assertDatabaseCount('google_accounts', 0);
    }

    public function test_access_denied_shows_the_cancel_page_and_spends_the_state(): void
    {
        $teacher = $this->makeTeacher();
        $this->fakeGoogle();
        $state = $this->stateFor($teacher);

        $res = $this->visitCallback(['error' => 'access_denied', 'state' => $state]);
        $this->assertResultPage($res, 200);
        $res->assertSee('ยกเลิกการเชื่อม Google Classroom แล้ว');

        $this->visitCallback(['state' => $state, 'code' => self::CODE])->assertStatus(400);

        // Any other error Google sends back is shown by name only.
        $this->visitCallback(['error' => 'admin_policy_enforced<script>', 'state' => $this->stateFor($teacher)])
            ->assertStatus(400)
            ->assertSee('(admin_policy_enforced)', false)
            ->assertDontSee('<script>', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('google_accounts', 0);
    }

    public function test_a_failed_code_exchange_explains_why_and_stores_nothing(): void
    {
        $teacher = $this->makeTeacher();
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::sequence()
            ->push(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400)
            ->push('down', 503)
            ->push(['error' => 'unauthorized_client'], 401)
            ->push(['error' => 'redirect_uri_mismatch'], 400),
        ]);

        $res = $this->visitCallback(['state' => $this->stateFor($teacher), 'code' => self::CODE]);
        $this->assertResultPage($res, 422);
        $res->assertSee('เชื่อม Google Classroom ไม่สำเร็จ')->assertSee('หมดอายุหรือถูกใช้ไปแล้ว');

        $this->visitCallback(['state' => $this->stateFor($teacher), 'code' => self::CODE])
            ->assertStatus(503)
            ->assertSee('ติดต่อ Google ไม่ได้');

        $this->visitCallback(['state' => $this->stateFor($teacher), 'code' => self::CODE])
            ->assertStatus(503)
            ->assertSee('ตรวจ GOOGLE_OAUTH_CLIENT_ID / SECRET')
            ->assertDontSee('GOOGLE_SERVER_CLIENT_ID');

        $this->visitCallback(['state' => $this->stateFor($teacher), 'code' => self::CODE])
            ->assertStatus(502)
            ->assertSee('Google ปฏิเสธคำขอ');

        $this->assertDatabaseCount('google_accounts', 0);
        $this->assertNoSecretInLogs();
    }

    public function test_missing_scopes_are_listed_in_thai_and_the_partial_grant_is_revoked(): void
    {
        $teacher = $this->makeTeacher();
        $missing = [GoogleScopes::PREFIX.'drive.readonly', GoogleScopes::PREFIX.'classroom.profile.emails'];
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(self::tokenBody(array_values(array_diff(GoogleScopes::REQUIRED, $missing))))]);

        $res = $this->visitCallback(['state' => $this->stateFor($teacher), 'code' => self::CODE]);

        $this->assertResultPage($res, 422);
        $res->assertSee('ต้องอนุญาตทุกสิทธิ์ที่แอปขอ')
            ->assertSee('สิทธิ์ที่ยังไม่ได้ติ๊กอนุญาต')
            ->assertSee(GoogleScopes::label($missing[0]))
            ->assertSee(GoogleScopes::label($missing[1]))
            ->assertDontSee(GoogleScopes::label(GoogleScopes::PREFIX.'drive.file'));
        Http::assertSent(fn (Request $r) => $r->url() === GoogleOAuth::REVOKE_URL && $r['token'] === self::REFRESH_TOKEN);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'userProfiles'));
        $this->assertDatabaseCount('google_accounts', 0);
        $this->assertNoSecretInLogs();
    }

    public function test_a_server_without_the_oauth_client(): void
    {
        $teacher = $this->makeTeacher();
        $this->fakeGoogle();
        $state = $this->stateFor($teacher);
        config(['services.google.client_secret' => '']);

        $this->asUser($teacher)->postJson('/api/v1/google/oauth/url')
            ->assertStatus(503)
            ->assertJsonPath('code', 'google_not_configured');

        $res = $this->visitCallback(['state' => $state, 'code' => self::CODE]);
        $this->assertResultPage($res, 503);
        $res->assertSee('ยังไม่ได้ตั้งค่า Google Classroom');

        $this->asUser($teacher)->getJson('/api/v1/google/status')
            ->assertOk()
            ->assertJsonPath('data.configured', false);
        Http::assertNothingSent();
    }

    public function test_only_active_teachers_get_a_url_and_a_state_dies_with_the_account(): void
    {
        $teacher = $this->makeTeacher();
        $this->fakeGoogle();
        $student = $this->enrollStudent($this->makeClassroom($teacher))['student'];

        $this->asUser($student)->postJson('/api/v1/google/oauth/url')->assertForbidden();
        $this->asGuest()->postJson('/api/v1/google/oauth/url')->assertUnauthorized();

        $state = $this->stateFor($teacher);
        $teacher->forceFill(['status' => User::STATUS_DISABLED])->save();
        $res = $this->visitCallback(['state' => $state, 'code' => self::CODE]);
        $this->assertResultPage($res, 403);

        Http::assertNothingSent();
        $this->assertDatabaseCount('google_accounts', 0);
    }

    public function test_the_redirect_uri_defaults_to_app_url(): void
    {
        config(['services.google.redirect_uri' => '', 'app.url' => 'https://teacherhelper.phuwish.com/']);
        $this->assertSame('https://teacherhelper.phuwish.com/google/oauth/callback', GoogleOAuth::redirectUri());

        $this->assertSame('https://teacherhelper.phuwish.com/google/oauth/callback', $this->consentQuery($this->makeTeacher())['redirect_uri']);
    }
}
