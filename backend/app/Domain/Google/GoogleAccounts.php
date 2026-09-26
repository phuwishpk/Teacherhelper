<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Models\GoogleAccount;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * A teacher's Google connection (DESIGN §18.5, §18.6 /google/*):
 *
 * - connect(): exchange the app's server auth code, check every scope of
 *   GoogleScopes::REQUIRED was granted (422 google_scope_missing, and the
 *   grant is revoked again), read the account id and e-mail
 *   (userProfiles/me), store the refresh token encrypted;
 * - status(): {connected, email, scopes, needs_reconnect, ...};
 * - disconnect(): revoke at Google, then delete the row (always deleted:
 *   the teacher asked for it; `revoked` says whether Google confirmed);
 * - call(): runs Classroom/Drive calls for a teacher and turns Google's
 *   errors into the API errors of GoogleErrors.
 *
 * No answer or log line ever carries a token.
 */
final class GoogleAccounts
{
    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly GoogleAccessTokens $tokens,
    ) {}

    /**
     * @return array<string, mixed> status()
     *
     * @throws ApiException
     */
    public function connect(User $teacher, #[\SensitiveParameter] string $serverAuthCode): array
    {
        if (! GoogleOAuth::isConfigured()) {
            throw GoogleErrors::notConfigured();
        }

        try {
            $grant = $this->oauth->exchangeCode($serverAuthCode);
        } catch (GoogleApiException $e) {
            if ($e->kind === GoogleApiException::INVALID_GRANT) {
                throw new ApiException('รหัสยืนยันจาก Google หมดอายุหรือถูกใช้ไปแล้ว กดเชื่อมอีกครั้ง', 'google_code_invalid', 422);
            }
            if ($e->kind === GoogleApiException::NOT_CONFIGURED) {
                Log::error('google.client_rejected', ['message' => $e->getMessage()]);
                throw new ApiException(
                    'Google ไม่รับ OAuth client ของเซิร์ฟเวอร์ (GOOGLE_OAUTH_CLIENT_ID / SECRET ไม่ตรงกับ GOOGLE_SERVER_CLIENT_ID ของแอป) กรุณาแจ้งผู้ดูแลระบบ',
                    'google_not_configured',
                    503,
                );
            }

            throw GoogleErrors::toApi($e);
        }

        $missing = GoogleScopes::missing($grant->scopes);
        if ($missing !== []) {
            // Nothing is stored; end the partial grant so it does not linger.
            $this->revokeQuietly($grant->refreshToken ?? $grant->accessToken);

            throw new ApiException(
                'ต้องอนุญาตทุกสิทธิ์ที่แอปขอ (Google Classroom และ Google Drive) กดเชื่อมอีกครั้งแล้วติ๊กอนุญาตให้ครบ',
                'google_scope_missing',
                422,
                ['scopes' => $missing],
            );
        }

        try {
            $profile = GoogleApi::withAccessToken($grant->accessToken)->profile();
        } catch (GoogleApiException $e) {
            throw GoogleErrors::toApi($e);
        }
        if ($profile['id'] === '') {
            throw new ApiException('Google ไม่ส่งข้อมูลบัญชีกลับมา ลองเชื่อมอีกครั้ง', 'google_error', 502);
        }

        $existing = GoogleAccount::query()->find($teacher->id);
        $inUse = GoogleAccount::query()->where('google_sub', $profile['id'])->where('user_id', '!=', $teacher->id)->exists();
        if ($inUse) {
            // No revoke here: the grant belongs to the same Google user and
            // client, so revoking would also cut off the other teacher.
            throw self::accountInUse();
        }

        $refreshToken = $grant->refreshToken;
        if ($refreshToken === null) {
            // Google sends a refresh token only with fresh consent. Reuse the
            // stored one for the same account while it still works.
            if ($existing === null || $existing->google_sub !== $profile['id'] || $existing->needsReconnect()) {
                throw new ApiException(
                    'Google ไม่ได้ให้สิทธิ์แบบใช้งานต่อเนื่องมา ไปที่ myaccount.google.com → ความปลอดภัย → การเชื่อมต่อกับแอปของบุคคลที่สาม ลบ EduVision ออก แล้วกดเชื่อมอีกครั้ง',
                    'google_refresh_token_missing',
                    422,
                );
            }
            $refreshToken = $existing->encrypted_refresh_token;
        }

        if ($existing !== null) {
            $this->tokens->forget($existing);
            if ($existing->google_sub !== $profile['id']) {
                // Switching to another Google account: end the old account's grant.
                $this->revokeQuietly($existing->encrypted_refresh_token);
            }
        }

        try {
            $account = GoogleAccount::query()->updateOrCreate(['user_id' => $teacher->id], [
                'google_sub' => $profile['id'],
                'email' => $profile['email'],
                'encrypted_refresh_token' => $refreshToken,
                'scopes' => implode(' ', $grant->scopes),
                'connected_at' => now(),
                'last_error' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw self::accountInUse();
        }
        $this->tokens->remember($account, $grant);

        Log::info('google.connected', ['user_id' => $teacher->id, 'scopes' => count($grant->scopes)]);

        return $this->status($teacher);
    }

    /**
     * @return array{connected: bool, email: string|null, scopes: list<string>, needs_reconnect: bool, last_error: string|null, connected_at: string|null, server_configured: bool}
     */
    public function status(User $teacher): array
    {
        $account = GoogleAccount::query()->find($teacher->id);

        return [
            'connected' => $account !== null,
            'email' => $account?->email,
            'scopes' => $account?->scopeList() ?? [],
            'needs_reconnect' => $account?->needsReconnect() ?? false,
            'last_error' => $account?->last_error,
            'connected_at' => $account?->connected_at?->toIso8601String(),
            'server_configured' => GoogleOAuth::isConfigured(),
        ];
    }

    /**
     * @return array<string, mixed> status() plus `revoked`
     */
    public function disconnect(User $teacher): array
    {
        $account = GoogleAccount::query()->find($teacher->id);
        $revoked = true;
        if ($account !== null) {
            $revoked = $this->revokeQuietly($account->encrypted_refresh_token);
            $this->tokens->forget($account);
            $account->delete();
            Log::info('google.disconnected', ['user_id' => $teacher->id, 'revoked' => $revoked]);
        }

        return [...$this->status($teacher), 'revoked' => $revoked];
    }

    /**
     * The teacher's usable account.
     *
     * @throws ApiException google_not_configured / google_not_connected / google_reconnect_required
     */
    public function accountOf(User $teacher): GoogleAccount
    {
        if (! GoogleOAuth::isConfigured()) {
            throw GoogleErrors::notConfigured();
        }
        $account = GoogleAccount::query()->find($teacher->id);
        if ($account === null) {
            throw GoogleErrors::notConnected();
        }
        if ($account->needsReconnect()) {
            throw GoogleErrors::reconnectRequired();
        }

        return $account;
    }

    /**
     * Runs $work with the teacher's Classroom/Drive client; Google's errors
     * become API errors ($notFound = the Thai message for a 404).
     *
     * @template T
     *
     * @param  Closure(GoogleApi, GoogleAccount): T  $work
     * @return T
     *
     * @throws ApiException
     */
    public function call(User $teacher, Closure $work, ?string $notFound = null): mixed
    {
        $account = $this->accountOf($teacher);

        try {
            return $work(GoogleApi::forAccount($account, $this->tokens), $account);
        } catch (GoogleApiException $e) {
            throw GoogleErrors::toApi($e, $notFound);
        }
    }

    private function revokeQuietly(#[\SensitiveParameter] string $token): bool
    {
        try {
            $this->oauth->revoke($token);

            return true;
        } catch (GoogleApiException $e) {
            Log::warning('google.revoke_failed', ['message' => $e->getMessage()]);

            return false;
        }
    }

    private static function accountInUse(): ApiException
    {
        return new ApiException('บัญชี Google นี้เชื่อมกับครูอีกคนในระบบอยู่แล้ว เลือกบัญชีอื่น', 'google_account_in_use', 409);
    }
}
