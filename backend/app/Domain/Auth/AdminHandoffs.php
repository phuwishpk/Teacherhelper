<?php

namespace App\Domain\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * One-time links from the app to the Filament panel (DESIGN §7.4, §7.5).
 * An admin who signs in on the unified login page of the app gets an API
 * token that opens nothing but /me, logout and POST /auth/admin-handoff;
 * that endpoint issues a link GET /admin/handoff/{token} which signs the
 * browser into the panel's web session.
 *
 * The token is 48 random characters bound to the admin's user id in the
 * database cache store for 60 seconds. Only its SHA-256 is stored, so the
 * cache table never holds a value that would open the panel. Redeeming it
 * is single-use even when two requests race: a `used` marker is written
 * with add(), which only one request can win (insert-or-ignore).
 */
final class AdminHandoffs
{
    public const TTL_SECONDS = 60;

    public const TOKEN_LENGTH = 48;

    public const KEY_PREFIX = 'admin:handoff:';

    /**
     * A new single-use token for $admin and when it stops working.
     *
     * @return array{token: string, expires_at: CarbonImmutable}
     */
    public function issue(User $admin): array
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH / 2));
        self::store()->put(self::key($token), (int) $admin->id, self::TTL_SECONDS);

        return ['token' => $token, 'expires_at' => CarbonImmutable::now()->addSeconds(self::TTL_SECONDS)];
    }

    /**
     * The user id $token was issued to, or null when it is malformed,
     * unknown, expired or already used. The token is spent either way.
     */
    public function consume(?string $token): ?int
    {
        if ($token === null || preg_match('/^[a-f0-9]{'.self::TOKEN_LENGTH.'}$/', $token) !== 1) {
            return null;
        }
        $key = self::key($token);
        $store = self::store();
        $userId = $store->get($key);
        if (! is_numeric($userId)) {
            return null;
        }
        // Only the first request writes the marker; a racing second one gets false.
        if (! $store->add($key.':used', 1, self::TTL_SECONDS)) {
            return null;
        }
        $store->forget($key);

        return (int) $userId;
    }

    public static function key(string $token): string
    {
        return self::KEY_PREFIX.hash('sha256', $token);
    }

    private static function store(): Repository
    {
        return Cache::store('database');
    }
}
