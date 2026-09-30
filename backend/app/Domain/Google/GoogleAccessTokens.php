<?php

namespace App\Domain\Google;

use App\Jobs\NotifyGoogleReconnectJob;
use App\Models\GoogleAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Access tokens for a connected teacher (DESIGN §18.1: the server acts while
 * the teacher is offline, e.g. sending grades when they publish).
 *
 * The refresh token (google_accounts, encrypted) is exchanged for an access
 * token, which stays in the cache (the database store in production)
 * encrypted with APP_KEY until 5 minutes before it expires. The cache key
 * includes an HMAC (APP_KEY) of the refresh token, so a reconnect never
 * reuses the previous grant's token.
 *
 * invalid_grant marks the account (last_error = invalid_grant): the app then
 * shows "ต้องเชื่อมบัญชี Google ใหม่" until the teacher connects again.
 */
final class GoogleAccessTokens
{
    private const EARLY_REFRESH = 300;

    public function __construct(private readonly GoogleOAuth $oauth) {}

    /**
     * @throws GoogleApiException invalid_grant, not_configured, unavailable, bad_request
     */
    public function get(GoogleAccount $account): string
    {
        $key = self::cacheKey($account);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                // APP_KEY rotated: fetch a new one below.
            }
        }

        try {
            $grant = $this->oauth->refresh($account->encrypted_refresh_token);
        } catch (GoogleApiException $e) {
            if ($e->kind === GoogleApiException::INVALID_GRANT) {
                self::markNeedsReconnect($account, GoogleAccount::ERROR_INVALID_GRANT);
            }

            throw $e;
        }

        Cache::put($key, Crypt::encryptString($grant->accessToken), max(60, $grant->expiresIn - self::EARLY_REFRESH));

        return $grant->accessToken;
    }

    /**
     * Seeds the cache with the access token that came with a fresh grant
     * (POST /google/connect), saving one refresh call.
     */
    public function remember(GoogleAccount $account, OAuthGrant $grant): void
    {
        Cache::put(self::cacheKey($account), Crypt::encryptString($grant->accessToken), max(60, $grant->expiresIn - self::EARLY_REFRESH));
    }

    /** Drop the cached token (Google answered 401 with it, or the account was disconnected). */
    public function forget(GoogleAccount $account): void
    {
        Cache::forget(self::cacheKey($account));
    }

    /**
     * Records why the teacher must connect again, and queues the one push
     * of this drop (NotifyGoogleReconnectJob, DESIGN §19.3). The cron sync
     * skips the teacher until a new connect clears last_error.
     */
    public static function markNeedsReconnect(GoogleAccount $account, string $error): void
    {
        if ($account->last_error === $error) {
            return;
        }
        $account->last_error = $error;
        $account->save();
        Log::warning('google.needs_reconnect', ['user_id' => $account->user_id, 'error' => $error]);
        if ($account->reconnect_notified_at === null) {
            NotifyGoogleReconnectJob::dispatch($account->user_id);
        }
    }

    private static function cacheKey(GoogleAccount $account): string
    {
        return 'google:access-token:'.$account->user_id.':'.hash_hmac('sha256', $account->encrypted_refresh_token, (string) config('app.key'));
    }
}
