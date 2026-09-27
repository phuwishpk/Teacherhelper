<?php

namespace App\Domain\Google;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The `state` of the browser connect flow (POST /google/oauth/url ->
 * Google's consent page -> GET /google/oauth/callback). The callback comes
 * from a browser without our bearer token, so the state is what says which
 * teacher asked: 32 random bytes (base64url) that map to the teacher's user
 * id in the database cache store for 10 minutes, usable once.
 *
 * Only a SHA-256 of the state is stored, so the cache table never holds a
 * value that would pass the callback. A replay of the same state and code
 * racing the first use still fails at Google, which accepts a code once.
 */
final class GoogleOAuthStates
{
    public const TTL_SECONDS = 600;

    public const KEY_PREFIX = 'google:oauth-state:';

    /** A new single-use state for $teacher. */
    public function issue(User $teacher): string
    {
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::store()->put(self::key($state), (int) $teacher->id, self::TTL_SECONDS);

        return $state;
    }

    /**
     * The user id $state was issued to, or null when it is unknown, expired
     * or already used. The state is spent either way.
     */
    public function consume(?string $state): ?int
    {
        if ($state === null || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $state) !== 1) {
            return null;
        }
        $userId = self::store()->pull(self::key($state));

        return is_numeric($userId) ? (int) $userId : null;
    }

    public static function key(string $state): string
    {
        return self::KEY_PREFIX.hash('sha256', $state);
    }

    private static function store(): Repository
    {
        return Cache::store('database');
    }
}
