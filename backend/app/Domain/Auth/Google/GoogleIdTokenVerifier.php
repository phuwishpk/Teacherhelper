<?php

namespace App\Domain\Auth\Google;

use App\Exceptions\ApiException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies a Google ID token (DESIGN §24.9.2) with firebase/php-jwt and the
 * keys of GoogleCerts:
 *
 *  1. header alg = RS256 with a kid;
 *  2. the kid's key from Google's JWKS (cached, refetched once when unknown);
 *  3. the RS256 signature, 60 seconds of leeway;
 *  4. iss = accounts.google.com or https://accounts.google.com;
 *  5. aud = one of GOOGLE_SIGNIN_CLIENT_IDS (azp is not checked: an Android
 *     token's azp is the Android client);
 *  6. exp not passed, iat not more than 60 s ahead, and not older than
 *     GOOGLE_SIGNIN_MAX_AGE;
 *  7. sub (<= 64 chars) and email present, email_verified true;
 *  8. the nonce, when the browser flow bound one to its state.
 *
 * Any failure but email_verified is the same 422 google_token_invalid. The
 * token never reaches a log line, an exception message or a response; the
 * log keeps the internal reason only.
 */
final class GoogleIdTokenVerifier
{
    public const LEEWAY = 60;

    public const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(private readonly GoogleCerts $certs) {}

    /**
     * @throws ApiException 422 google_token_invalid / google_email_unverified, 503 google_unavailable or google_signin_not_configured
     */
    public function verify(#[\SensitiveParameter] string $idToken, ?string $expectedNonce = null): VerifiedGoogleIdentity
    {
        $clientIds = GoogleSignInConfig::clientIds();
        if ($clientIds === []) {
            throw GoogleSignInErrors::notConfigured();
        }

        $parts = explode('.', $idToken);
        if (count($parts) !== 3 || strlen($idToken) > 8192) {
            throw self::invalid('malformed');
        }
        $header = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null) || $header['kid'] === '') {
            throw self::invalid('header');
        }

        $jwk = $this->certs->key($header['kid']);
        if ($jwk === null) {
            throw self::invalid('unknown_kid');
        }

        $now = now()->getTimestamp();
        $previous = [JWT::$leeway, JWT::$timestamp];
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = $now;
        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet(['keys' => [$jwk + ['alg' => 'RS256']]], 'RS256'));
        } catch (Throwable) {
            throw self::invalid('signature_or_time');
        } finally {
            [JWT::$leeway, JWT::$timestamp] = $previous;
        }

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw self::invalid('iss');
        }
        if (! is_string($claims['aud'] ?? null) || ! in_array($claims['aud'], $clientIds, true)) {
            throw self::invalid('aud');
        }
        if (! is_numeric($claims['exp'] ?? null) || ! is_numeric($claims['iat'] ?? null)) {
            throw self::invalid('time_claims');
        }
        $iat = (int) $claims['iat'];
        if ($iat > $now + self::LEEWAY || $now - $iat > GoogleSignInConfig::maxAge()) {
            throw self::invalid('age');
        }
        if ($expectedNonce !== null && (! is_string($claims['nonce'] ?? null) || ! hash_equals($expectedNonce, $claims['nonce']))) {
            throw self::invalid('nonce');
        }

        $sub = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (! is_string($sub) || $sub === '' || strlen($sub) > 64 || ! is_string($email) || ! str_contains($email, '@') || strlen($email) > 255) {
            throw self::invalid('sub_or_email');
        }
        $verified = $claims['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true') {
            Log::info('google_signin.verify', ['result' => 'email_unverified']);

            throw GoogleSignInErrors::emailUnverified();
        }

        $string = fn (string $key, int $max) => is_string($claims[$key] ?? null) && $claims[$key] !== '' ? mb_substr($claims[$key], 0, $max) : null;

        return new VerifiedGoogleIdentity(
            sub: $sub,
            email: mb_strtolower(trim($email)),
            name: $string('name', 255),
            picture: $string('picture', 512),
            hd: $string('hd', 255),
        );
    }

    private static function invalid(string $reason): ApiException
    {
        Log::info('google_signin.verify', ['result' => 'invalid', 'reason' => $reason]);

        return GoogleSignInErrors::tokenInvalid();
    }
}
