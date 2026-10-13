<?php

namespace App\Domain\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "เข้าใช้แทน" (DESIGN §29.6, §29.14): an admin opens the web app as any
 * active teacher or student, with that user's full rights, to see what they
 * see. The panel issues a one-time ticket (60 seconds, SHA-256 only in the
 * database cache store, like AdminHandoffs) that the web app redeems for a
 * Sanctum token of the user. The token's name says who is behind it, so
 * /me can tell the app to show the banner, and both the start and the end
 * go to audit_logs. Another admin is never impersonated.
 */
final class Impersonations
{
    public const TTL_SECONDS = 60;

    public const TOKEN_LENGTH = 48;

    public const KEY_PREFIX = 'admin:impersonate:';

    /** The Sanctum token name: `impersonation:{admin id}`. */
    public const TOKEN_NAME_PREFIX = 'impersonation:';

    public const SESSION_HOURS = 2;

    /** Whom an admin may act as: an active teacher or student, never an admin. */
    public static function allowed(User $admin, User $target): bool
    {
        return $admin->isAdmin() && $admin->isActive()
            && ! $target->isAdmin() && $target->isActive() && ! $target->isMerged();
    }

    public function issueTicket(User $admin, User $target): string
    {
        $ticket = bin2hex(random_bytes(self::TOKEN_LENGTH / 2));
        self::store()->put(self::key($ticket), ['admin_id' => (int) $admin->id, 'user_id' => (int) $target->id], self::TTL_SECONDS);
        Log::info('admin.impersonate', ['result' => 'ticket', 'admin_id' => $admin->id, 'user_id' => $target->id]);

        return $ticket;
    }

    /**
     * The admin and the user behind a ticket, or null when it is malformed,
     * unknown, expired, used, or the pair is no longer allowed. Single-use
     * even when two requests race (the `used` marker is written with add()).
     *
     * @return array{admin: User, user: User}|null
     */
    public function redeem(?string $ticket): ?array
    {
        if ($ticket === null || preg_match('/^[a-f0-9]{'.self::TOKEN_LENGTH.'}$/', $ticket) !== 1) {
            return null;
        }
        $key = self::key($ticket);
        $store = self::store();
        $data = $store->get($key);
        if (! is_array($data) || ! $store->add($key.':used', 1, self::TTL_SECONDS)) {
            return null;
        }
        $store->forget($key);
        $admin = User::query()->find($data['admin_id'] ?? 0);
        $user = User::query()->find($data['user_id'] ?? 0);
        if ($admin === null || $user === null || ! self::allowed($admin, $user)) {
            Log::info('admin.impersonate', ['result' => 'not_allowed', 'admin_id' => $data['admin_id'] ?? null, 'user_id' => $data['user_id'] ?? null]);

            return null;
        }

        return ['admin' => $admin, 'user' => $user];
    }

    /** The user's token for the admin's session: the user's own role ability, two hours. */
    public function start(User $admin, User $user, ?string $deviceName): NewAccessToken
    {
        $token = $user->createToken(self::TOKEN_NAME_PREFIX.$admin->id, [$user->role], now()->addHours(self::SESSION_HOURS));
        AuditLog::record($admin, AuditLog::IMPERSONATE_START, $user, array_filter(['device' => $deviceName, 'token_id' => $token->accessToken->id]));
        Log::info('admin.impersonate', ['result' => 'start', 'admin_id' => $admin->id, 'user_id' => $user->id]);

        return $token;
    }

    /** Records the end of a session when its token is revoked (logout). */
    public static function stop(User $user, PersonalAccessToken $token): void
    {
        $adminId = self::adminIdOf($token);
        if ($adminId === null) {
            return;
        }
        $admin = User::query()->find($adminId);
        if ($admin !== null) {
            AuditLog::record($admin, AuditLog::IMPERSONATE_STOP, $user, ['token_id' => $token->id]);
        }
        Log::info('admin.impersonate', ['result' => 'stop', 'admin_id' => $adminId, 'user_id' => $user->id]);
    }

    /**
     * `{id, name}` of the admin behind the user's current token, or null for
     * an ordinary session (UserResource `impersonated_by`).
     *
     * @return array{id: int, name: string}|null
     */
    public static function adminFor(User $user): ?array
    {
        $token = $user->currentAccessToken();
        $adminId = $token instanceof PersonalAccessToken ? self::adminIdOf($token) : null;
        if ($adminId === null) {
            return null;
        }
        $admin = User::query()->find($adminId);

        return $admin === null ? null : ['id' => $admin->id, 'name' => $admin->name];
    }

    public static function adminIdOf(PersonalAccessToken $token): ?int
    {
        $name = (string) $token->name;
        if (! str_starts_with($name, self::TOKEN_NAME_PREFIX)) {
            return null;
        }
        $id = substr($name, strlen(self::TOKEN_NAME_PREFIX));

        return ctype_digit($id) ? (int) $id : null;
    }

    public static function key(string $ticket): string
    {
        return self::KEY_PREFIX.hash('sha256', $ticket);
    }

    private static function store(): Repository
    {
        return Cache::store('database');
    }
}
