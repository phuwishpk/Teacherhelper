<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Google\GoogleCerts;
use App\Domain\Auth\Google\GoogleIdTokenVerifier;
use App\Domain\Auth\Google\VerifiedGoogleIdentity;
use App\Exceptions\ApiException;
use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DESIGN §24.9.2 / §24.16: every rule of GoogleIdTokenVerifier with keys
 * made in the test and a faked JWKS endpoint.
 */
class GoogleIdTokenVerifierTest extends TestCase
{
    use GoogleSignInFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSignIn();
    }

    public function test_a_valid_token_gives_the_verified_identity(): void
    {
        $this->fakeCerts();

        $identity = $this->verify($this->idToken(['email' => 'Kru.Somsri@School.AC.th', 'hd' => 'school.ac.th']));

        $this->assertSame('110000000000000000001', $identity->sub);
        $this->assertSame('kru.somsri@school.ac.th', $identity->email);
        $this->assertSame('school.ac.th', $identity->domain());
        $this->assertSame('ครูสมศรี', $identity->name);
        $this->assertSame('https://lh3.googleusercontent.com/a/test', $identity->picture);
        $this->assertSame('school.ac.th', $identity->hd);
    }

    public function test_any_configured_client_id_is_an_accepted_audience(): void
    {
        $this->fakeCerts();

        $this->assertSame('110000000000000000001', $this->verify($this->idToken(['aud' => self::ANDROID_AUD]))->sub);
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['aud' => 'someone-else.apps.googleusercontent.com'])));
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['aud' => [self::SIGNIN_CLIENT_ID]])));
    }

    public function test_a_wrong_signature_is_refused(): void
    {
        $this->fakeCerts(['kid-1', 'kid-2']);

        // Signed with kid-2's key but claims kid-1.
        $this->assertSame('google_token_invalid', $this->error($this->idToken([], 'kid-1', 'kid-2')));
        // A tampered payload.
        [$h, , $s] = explode('.', $this->idToken());
        $forged = JWT::urlsafeB64Encode(json_encode(['sub' => 'x', 'email' => 'a@b.c', 'email_verified' => true, 'aud' => self::SIGNIN_CLIENT_ID, 'iss' => 'accounts.google.com', 'iat' => time(), 'exp' => time() + 60]));
        $this->assertSame('google_token_invalid', $this->error("{$h}.{$forged}.{$s}"));
        $this->assertSame('google_token_invalid', $this->error('not-a-jwt'));
    }

    public function test_only_rs256_with_a_kid_is_accepted(): void
    {
        $this->fakeCerts();
        $now = now()->getTimestamp();
        $claims = ['iss' => 'accounts.google.com', 'aud' => self::SIGNIN_CLIENT_ID, 'sub' => '1', 'email' => 'a@school.ac.th', 'email_verified' => true, 'iat' => $now, 'exp' => $now + 600];

        $this->assertSame('google_token_invalid', $this->error(JWT::encode($claims, str_repeat('k', 64), 'HS256', 'kid-1')));
        $this->assertSame('google_token_invalid', $this->error(JWT::encode($claims, self::rsaKey()['private'], 'RS512', 'kid-1')));
        $this->assertSame('google_token_invalid', $this->error(JWT::encode($claims, self::rsaKey()['private'], 'RS256')));
    }

    public function test_an_unknown_kid_refetches_the_keys_once(): void
    {
        Http::fake([GoogleCerts::URL => Http::sequence()
            ->push(['keys' => [self::rsaKey('kid-1')['jwk']]], 200, ['Cache-Control' => 'max-age=3600'])
            ->push(['keys' => [self::rsaKey('kid-1')['jwk'], self::rsaKey('kid-2')['jwk']]], 200, ['Cache-Control' => 'max-age=3600'])
            ->push(['keys' => [self::rsaKey('kid-3')['jwk']]], 200, ['Cache-Control' => 'max-age=3600']),
        ]);

        $this->verify($this->idToken([], 'kid-1'));
        Http::assertSentCount(1);

        // Google rotated its keys: kid-2 is not cached, so the set is fetched again (after the first minute).
        $this->travel(61)->seconds();
        $this->assertSame('110000000000000000001', $this->verify($this->idToken([], 'kid-2'))->sub);
        Http::assertSentCount(2);

        // Another unknown kid within the minute is refused without asking Google again.
        $this->assertSame('google_token_invalid', $this->error($this->idToken([], 'kid-3')));
        Http::assertSentCount(2);
    }

    public function test_the_keys_are_cached_for_the_max_age_google_sends(): void
    {
        $this->fakeCerts(['kid-1'], 'public, max-age=19800, must-revalidate, no-transform');

        $this->verify($this->idToken());
        $this->travel(19000)->seconds();
        $this->verify($this->idToken());
        Http::assertSentCount(1);

        $this->travel(1000)->seconds();
        $this->verify($this->idToken());
        Http::assertSentCount(2);
    }

    public function test_the_cache_lifetime_is_clamped(): void
    {
        $response = fn (?string $cacheControl) => new Response(new Psr7Response(200, $cacheControl === null ? [] : ['Cache-Control' => $cacheControl]));

        $this->assertSame(19800, GoogleCerts::ttl($response('public, max-age=19800, must-revalidate')));
        $this->assertSame(300, GoogleCerts::ttl($response('max-age=10')));
        $this->assertSame(86400, GoogleCerts::ttl($response('max-age=999999')));
        $this->assertSame(3600, GoogleCerts::ttl($response(null)));
        $this->assertSame(3600, GoogleCerts::ttl($response('no-cache')));
    }

    public function test_google_down_is_503(): void
    {
        Http::fake([GoogleCerts::URL => Http::response('', 500)]);
        $this->assertSame('google_unavailable', $this->error($this->idToken()));

        Http::fake([GoogleCerts::URL => Http::failedConnection()]);
        $this->assertSame('google_unavailable', $this->error($this->idToken()));
    }

    public function test_issuer_and_times_are_checked(): void
    {
        $this->fakeCerts();
        $now = now()->getTimestamp();

        $this->assertSame('110000000000000000001', $this->verify($this->idToken(['iss' => 'accounts.google.com']))->sub);
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['iss' => 'https://evil.example'])));
        // Expired (beyond the 60 s leeway), and without exp at all.
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['iat' => $now - 300, 'exp' => $now - 61])));
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['exp' => null])));
        // Within the leeway it still passes.
        $this->verify($this->idToken(['iat' => $now - 300, 'exp' => $now - 30]));
        // Issued in the future by more than 60 s.
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['iat' => $now + 120, 'exp' => $now + 3600])));
        // Older than GOOGLE_SIGNIN_MAX_AGE (600 s) although Google would still accept it.
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['iat' => $now - 601, 'exp' => $now + 2999])));
        $this->verify($this->idToken(['iat' => $now - 590, 'exp' => $now + 3000]));
    }

    public function test_sub_and_a_verified_email_are_required(): void
    {
        $this->fakeCerts();

        $this->assertSame('google_email_unverified', $this->error($this->idToken(['email_verified' => false])));
        $this->assertSame('google_email_unverified', $this->error($this->idToken(['email_verified' => null])));
        $this->verify($this->idToken(['email_verified' => 'true']));
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['sub' => null])));
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['sub' => str_repeat('1', 65)])));
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['email' => null])));
    }

    public function test_the_nonce_of_the_browser_flow_must_match(): void
    {
        $this->fakeCerts();

        $this->assertSame('110000000000000000001', $this->verify($this->idToken(['nonce' => 'nonce-abc']), 'nonce-abc')->sub);
        $this->assertSame('google_token_invalid', $this->error($this->idToken(['nonce' => 'nonce-other']), 'nonce-abc'));
        $this->assertSame('google_token_invalid', $this->error($this->idToken(), 'nonce-abc'));
    }

    public function test_not_configured_is_503(): void
    {
        config(['services.google_signin.client_ids' => '']);

        $this->assertSame('google_signin_not_configured', $this->error('a.b.c'));
    }

    public function test_the_token_never_reaches_the_log(): void
    {
        $this->captureSignInLog();
        $this->fakeCerts();
        $tokens = [$this->idToken(['iss' => 'bad']), $this->idToken(['email_verified' => false]), $this->idToken()];
        foreach ($tokens as $token) {
            try {
                $this->verify($token);
            } catch (ApiException) {
            }
        }

        $log = json_encode($this->signinLog, JSON_UNESCAPED_UNICODE);
        $this->assertNotSame([], $this->signinLog);
        foreach ($tokens as $token) {
            $this->assertStringNotContainsString(explode('.', $token)[2], $log);
        }
        $this->assertStringNotContainsString('kru.somsri', $log);
    }

    private function verify(string $token, ?string $nonce = null): VerifiedGoogleIdentity
    {
        return app(GoogleIdTokenVerifier::class)->verify($token, $nonce);
    }

    private function error(string $token, ?string $nonce = null): string
    {
        try {
            $this->verify($token, $nonce);
        } catch (ApiException $e) {
            return $e->errorCode;
        }
        $this->fail('the token was accepted');
    }
}
