<?php

namespace App\Domain\Notifications\Fcm;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * OAuth 2.0 access tokens for the FCM HTTP v1 API from a service account
 * (Google's "JWT bearer" flow; no google/apiclient):
 *
 * 1. sign a JWT with the account's private key (RS256, firebase/php-jwt):
 *    iss = client_email, scope = firebase.messaging, aud = token_uri,
 *    valid for one hour;
 * 2. POST it to token_uri as grant_type jwt-bearer -> {access_token, expires_in};
 * 3. keep the token in the cache (the database store in production),
 *    encrypted with APP_KEY, until 5 minutes before it expires.
 *
 * The Laravel HTTP client makes the call, so tests fake it with Http::fake().
 */
final class AccessTokens
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const LIFETIME = 3600;

    private const EARLY_REFRESH = 300;

    public function __construct(private readonly ServiceAccount $account, private readonly int $timeout = 10) {}

    public function get(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                // APP_KEY rotated: fetch a new one below.
            }
        }

        [$token, $expiresIn] = $this->fetch();
        $ttl = max(60, $expiresIn - self::EARLY_REFRESH);
        Cache::put($this->cacheKey(), Crypt::encryptString($token), $ttl);

        return $token;
    }

    /** Drop the cached token (FCM answered 401 with it). */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * @return array{0: string, 1: int} access token, seconds until it expires
     */
    private function fetch(): array
    {
        $now = time();
        $assertion = JWT::encode([
            'iss' => $this->account->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $this->account->tokenUri,
            'iat' => $now,
            'exp' => $now + self::LIFETIME,
        ], $this->account->privateKey(), 'RS256', $this->account->privateKeyId);

        try {
            $response = Http::asForm()->acceptJson()->timeout($this->timeout)->post($this->account->tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
        } catch (ConnectionException $e) {
            throw new FcmAuthFailed('token endpoint unreachable: '.$e->getMessage());
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            $error = $response->json('error');
            throw new FcmAuthFailed('token exchange failed: HTTP '.$response->status().(is_string($error) ? ' '.$error : ''));
        }

        $expiresIn = $response->json('expires_in');

        return [$token, is_numeric($expiresIn) ? (int) $expiresIn : self::LIFETIME];
    }

    private function cacheKey(): string
    {
        return 'fcm:access-token:'.sha1($this->account->clientEmail.'|'.$this->account->projectId);
    }
}
