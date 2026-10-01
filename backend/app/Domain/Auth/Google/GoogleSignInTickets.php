<?php

namespace App\Domain\Auth\Google;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The single-use values of Google sign-in (DESIGN §24.9.3 step 5, §24.9.4),
 * all in the `database` cache store under the SHA-256 of the value, so the
 * cache table never holds anything that would pass a check:
 *
 * - link ticket (48 hex, 10 minutes): a verified Google account that is
 *   linked to nobody yet, for registration or the student's first PIN/QR
 *   confirmation, so the client never sends the ID token twice;
 * - login ticket (48 hex, 60 seconds): the browser flow's hand-over from the
 *   callback to the web app (like the admin handoff, §7.4);
 * - state (32 random bytes base64url, 10 minutes): the browser flow's
 *   purpose, intent, nonce and user.
 *
 * Spending is race-safe: a `used` marker is written with add(), which only
 * one request can win.
 */
final class GoogleSignInTickets
{
    public const LINK_TTL = 600;

    public const LOGIN_TTL = 60;

    public const STATE_TTL = 600;

    public const TICKET_LENGTH = 48;

    private const LINK = 'google-signin:link:';

    private const LOGIN = 'google-signin:login:';

    private const STATE = 'google-signin:state:';

    public function issueLink(VerifiedGoogleIdentity $identity): string
    {
        return $this->issueTicket(self::LINK, ['identity' => $identity->toArray()], self::LINK_TTL);
    }

    /** The identity behind a link ticket without spending it, or null. */
    public function peekLink(?string $ticket): ?VerifiedGoogleIdentity
    {
        if (! self::wellFormed($ticket)) {
            return null;
        }
        $key = self::LINK.hash('sha256', (string) $ticket);
        if (self::store()->has($key.':used')) {
            return null;
        }
        $data = self::store()->get($key);

        return is_array($data) ? VerifiedGoogleIdentity::fromArray($data['identity'] ?? null) : null;
    }

    /** The identity behind a link ticket, or null when unknown, expired or spent. Spends it. */
    public function consumeLink(?string $ticket): ?VerifiedGoogleIdentity
    {
        $data = $this->consumeTicket(self::LINK, $ticket, self::LINK_TTL);

        return $data === null ? null : VerifiedGoogleIdentity::fromArray($data['identity'] ?? null);
    }

    public function issueLogin(VerifiedGoogleIdentity $identity, string $intent): string
    {
        return $this->issueTicket(self::LOGIN, ['identity' => $identity->toArray(), 'intent' => $intent], self::LOGIN_TTL);
    }

    /** @return array{identity: VerifiedGoogleIdentity, intent: string}|null */
    public function consumeLogin(?string $ticket): ?array
    {
        $data = $this->consumeTicket(self::LOGIN, $ticket, self::LOGIN_TTL);
        $identity = $data === null ? null : VerifiedGoogleIdentity::fromArray($data['identity'] ?? null);

        return $identity === null ? null : ['identity' => $identity, 'intent' => (string) ($data['intent'] ?? 'staff')];
    }

    /**
     * @param  array{purpose: string, intent: string, nonce: string, user_id: int|null, accept_notice: bool}  $data
     */
    public function issueState(array $data): string
    {
        $state = self::random32();
        self::store()->put(self::STATE.hash('sha256', $state), $data, self::STATE_TTL);

        return $state;
    }

    /** @return array{purpose: string, intent: string, nonce: string, user_id: int|null, accept_notice: bool}|null */
    public function consumeState(?string $state): ?array
    {
        if ($state === null || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $state) !== 1) {
            return null;
        }
        $data = self::store()->pull(self::STATE.hash('sha256', $state));

        return is_array($data) && is_string($data['nonce'] ?? null) ? $data : null;
    }

    /** 32 random bytes, base64url without padding (a state or a nonce). */
    public static function random32(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @param  array<string, mixed>  $data */
    private function issueTicket(string $prefix, array $data, int $ttl): string
    {
        $ticket = bin2hex(random_bytes(self::TICKET_LENGTH / 2));
        self::store()->put($prefix.hash('sha256', $ticket), $data, $ttl);

        return $ticket;
    }

    /** @return array<string, mixed>|null */
    private function consumeTicket(string $prefix, ?string $ticket, int $ttl): ?array
    {
        if (! self::wellFormed($ticket)) {
            return null;
        }
        $key = $prefix.hash('sha256', (string) $ticket);
        $store = self::store();
        $data = $store->get($key);
        if (! is_array($data)) {
            return null;
        }
        if (! $store->add($key.':used', 1, $ttl)) {
            return null;
        }
        $store->forget($key);

        return $data;
    }

    private static function wellFormed(?string $ticket): bool
    {
        return $ticket !== null && preg_match('/^[a-f0-9]{'.self::TICKET_LENGTH.'}$/', $ticket) === 1;
    }

    private static function store(): Repository
    {
        return Cache::store('database');
    }
}
