<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Google\GoogleCerts;
use App\Models\School;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google sign-in without Google (DESIGN §24.16): RSA keys made in the test,
 * a faked JWKS endpoint (Http::fake) and ID tokens signed with those keys.
 * No test reaches Google.
 */
trait GoogleSignInFixtures
{
    protected const SIGNIN_CLIENT_ID = 'test-signin-web.apps.googleusercontent.com';

    protected const ANDROID_AUD = 'test-signin-android.apps.googleusercontent.com';

    protected const SIGNIN_SECRET = 'test-signin-secret-not-real';

    /** @var array<string, array{private: string, jwk: array<string, string>}> kid => key, shared by the whole run (key generation is slow) */
    private static array $rsaKeys = [];

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    protected array $signinLog = [];

    protected function configureSignIn(bool $web = false): void
    {
        config([
            'services.google_signin.client_ids' => self::SIGNIN_CLIENT_ID.', '.self::ANDROID_AUD,
            'services.google_signin.client_secret' => $web ? self::SIGNIN_SECRET : null,
            'services.google_signin.app_url' => $web ? 'https://app.example.test/' : null,
            'services.google_signin.redirect_uri' => 'https://api.example.test/auth/google/callback',
            'services.google_signin.max_age' => 600,
        ]);
    }

    /** @return array{private: string, jwk: array<string, string>} */
    protected static function rsaKey(string $kid = 'kid-1'): array
    {
        if (! isset(self::$rsaKeys[$kid])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            $details = openssl_pkey_get_details($key);
            self::$rsaKeys[$kid] = [
                'private' => $private,
                'jwk' => [
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => $kid,
                    'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
                    'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
                ],
            ];
        }

        return self::$rsaKeys[$kid];
    }

    /**
     * Fakes Google's JWKS with the keys of $kids (and anything else in $more).
     *
     * @param  list<string>  $kids
     * @param  array<string, mixed>  $more  extra Http::fake rules
     */
    protected function fakeCerts(array $kids = ['kid-1'], string $cacheControl = 'public, max-age=19800, must-revalidate, no-transform', array $more = []): void
    {
        Http::fake([
            GoogleCerts::URL => Http::response(['keys' => array_map(fn ($kid) => self::rsaKey($kid)['jwk'], $kids)], 200, ['Cache-Control' => $cacheControl]),
            ...$more,
        ]);
    }

    /**
     * A Google ID token signed with the test key $kid.
     *
     * @param  array<string, mixed>  $claims  overrides; a null value removes the claim
     */
    protected function idToken(array $claims = [], string $kid = 'kid-1', ?string $signWith = null, array $header = []): string
    {
        $now = now()->getTimestamp();
        $payload = array_filter([
            'iss' => 'https://accounts.google.com',
            'azp' => self::ANDROID_AUD,
            'aud' => self::SIGNIN_CLIENT_ID,
            'sub' => '110000000000000000001',
            'email' => 'kru.somsri@school.ac.th',
            'email_verified' => true,
            'name' => 'ครูสมศรี',
            'picture' => 'https://lh3.googleusercontent.com/a/test',
            'iat' => $now,
            'exp' => $now + 3600,
            ...$claims,
        ], fn ($v) => $v !== null);

        return JWT::encode($payload, self::rsaKey($signWith ?? $kid)['private'], 'RS256', $kid, $header);
    }

    protected function linkGoogle(User $user, string $sub = '110000000000000000001', string $email = 'kru.somsri@school.ac.th', string $via = 'self'): UserGoogleIdentity
    {
        return UserGoogleIdentity::create([
            'user_id' => $user->id,
            'google_sub' => $sub,
            'email' => $email,
            'linked_via' => $via,
            'linked_at' => now(),
        ]);
    }

    protected function allowStudents(School $school, ?array $domains = null): void
    {
        $school->forceFill(['student_google_signin' => true, 'google_signin_domains' => $domains])->save();
    }

    protected function captureSignInLog(): void
    {
        Log::listen(function ($event) {
            $this->signinLog[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }
}
