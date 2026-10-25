<?php

namespace App\Domain\Auth\Google;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Google's ID-token signing keys (JWKS, DESIGN §24.9.2 step 2), fetched with
 * the Laravel HTTP client and cached in the `database` store for the
 * Cache-Control max-age Google sends (clamped to 5 minutes .. 24 hours; one
 * hour without one). Google rotates keys, so a `kid` that is not in the
 * cached set refetches once, at most once a minute (Cache::add is atomic, so
 * a burst of tokens with a made-up kid cannot hammer Google).
 */
final class GoogleCerts
{
    public const URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public const CACHE_KEY = 'google-signin:certs';

    public const REFRESH_GUARD_KEY = 'google-signin:certs-refresh';

    public const MIN_TTL = 300;

    public const MAX_TTL = 86400;

    public const DEFAULT_TTL = 3600;

    /**
     * The JWK of $kid, or null when Google does not list it (even after one
     * refetch).
     *
     * @return array<string, mixed>|null
     *
     * @throws ApiException 503 google_unavailable when the keys cannot be fetched
     */
    public function key(string $kid): ?array
    {
        $keys = self::store()->get(self::CACHE_KEY);
        if (! is_array($keys)) {
            $keys = $this->fetch();
            // A fresh set was just fetched: an unknown kid is simply unknown.
            self::store()->add(self::REFRESH_GUARD_KEY, 1, 60);

            return self::find($keys, $kid);
        }

        $key = self::find($keys, $kid);
        if ($key !== null) {
            return $key;
        }

        if (! self::store()->add(self::REFRESH_GUARD_KEY, 1, 60)) {
            return null;
        }

        return self::find($this->fetch(), $kid);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ApiException 503 google_unavailable
     */
    private function fetch(): array
    {
        try {
            $response = Http::acceptJson()->timeout(GoogleSignInConfig::timeout())->get(self::URL);
        } catch (ConnectionException) {
            throw GoogleSignInErrors::unavailable();
        }

        $keys = $response->successful() ? $response->json('keys') : null;
        if (! is_array($keys) || $keys === []) {
            throw GoogleSignInErrors::unavailable();
        }
        $keys = array_values(array_filter($keys, fn ($k) => is_array($k) && is_string($k['kid'] ?? null)));

        self::store()->put(self::CACHE_KEY, $keys, self::ttl($response));

        return $keys;
    }

    /** Seconds from Cache-Control max-age, clamped (DESIGN §24.9.2). */
    public static function ttl(Response $response): int
    {
        if (preg_match('/(?:^|[,\s])max-age\s*=\s*(\d+)/i', (string) $response->header('Cache-Control'), $m) !== 1) {
            return self::DEFAULT_TTL;
        }

        return min(self::MAX_TTL, max(self::MIN_TTL, (int) $m[1]));
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     * @return array<string, mixed>|null
     */
    private static function find(array $keys, string $kid): ?array
    {
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }

        return null;
    }

    private static function store(): Repository
    {
        return Cache::store('database');
    }
}
