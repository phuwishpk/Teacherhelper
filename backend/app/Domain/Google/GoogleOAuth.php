<?php

namespace App\Domain\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google's OAuth 2.0 endpoints for the teacher's account (DESIGN §18.5),
 * with the Web client of config('services.google'):
 *
 * - authorizationUrl(): the consent page of the browser flow
 *   (POST /google/oauth/url, then GET /google/oauth/callback);
 * - exchangeCode(): a one-time code -> access + refresh token. The server
 *   auth code of the Android app (google_sign_in authorizeServer) has no
 *   redirect URI; the browser flow's code needs redirectUri() again;
 * - refresh(): refresh token -> a new access token (about an hour);
 * - revoke(): ends the grant (DELETE /google/disconnect).
 *
 * Tokens travel only in form bodies, never in URLs, so no log line or
 * exception message can carry one.
 */
final class GoogleOAuth
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public static function isConfigured(): bool
    {
        return trim((string) config('services.google.client_id')) !== ''
            && trim((string) config('services.google.client_secret')) !== '';
    }

    /**
     * Where Google sends the browser back to (GET /google/oauth/callback).
     * It must be listed, character for character, under "Authorized
     * redirect URIs" of the Web client. GOOGLE_OAUTH_REDIRECT_URI, or
     * APP_URL + /google/oauth/callback.
     */
    public static function redirectUri(): string
    {
        $configured = trim((string) config('services.google.redirect_uri'));

        return $configured !== '' ? $configured : rtrim((string) config('app.url'), '/').'/google/oauth/callback';
    }

    /**
     * Google's consent page for the browser flow: every scope of
     * GoogleScopes::REQUIRED, offline access and a forced consent screen,
     * so Google always answers with a refresh token.
     */
    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => (string) config('services.google.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', GoogleScopes::REQUIRED),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * $redirectUri: '' for the app's server auth code, redirectUri() for a
     * code of the browser flow (Google compares it with the consent request).
     *
     * @throws GoogleApiException invalid_grant (code used or expired), not_configured, unavailable, bad_request
     */
    public function exchangeCode(#[\SensitiveParameter] string $code, string $redirectUri = ''): OAuthGrant
    {
        return $this->grant('code exchange', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * @throws GoogleApiException invalid_grant (revoked / expired), not_configured, unavailable, bad_request
     */
    public function refresh(#[\SensitiveParameter] string $refreshToken): OAuthGrant
    {
        return $this->grant('token refresh', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Revokes the grant behind $token (a refresh token revokes the whole
     * grant). A token Google no longer knows counts as revoked.
     *
     * @throws GoogleApiException unavailable
     */
    public function revoke(#[\SensitiveParameter] string $token): void
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(self::timeout())->post(self::REVOKE_URL, ['token' => $token]);
        } catch (ConnectionException) {
            throw new GoogleApiException(GoogleApiException::UNAVAILABLE, 'token revoke failed: oauth2.googleapis.com unreachable');
        }

        if ($response->successful() || ($response->status() === 400 && $response->json('error') === 'invalid_token')) {
            return;
        }

        throw new GoogleApiException(
            $response->serverError() || $response->status() === 429 ? GoogleApiException::UNAVAILABLE : GoogleApiException::BAD_REQUEST,
            'token revoke failed: HTTP '.$response->status(),
            $response->status(),
        );
    }

    /**
     * @param  array<string, string>  $form
     */
    private function grant(string $what, #[\SensitiveParameter] array $form): OAuthGrant
    {
        if (! self::isConfigured()) {
            throw new GoogleApiException(GoogleApiException::NOT_CONFIGURED, 'GOOGLE_OAUTH_CLIENT_ID / GOOGLE_OAUTH_CLIENT_SECRET are not set');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(self::timeout())->post(self::TOKEN_URL, [
                ...$form,
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
            ]);
        } catch (ConnectionException) {
            throw new GoogleApiException(GoogleApiException::UNAVAILABLE, "{$what} failed: oauth2.googleapis.com unreachable");
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw self::grantError($what, $response);
        }

        $refresh = $response->json('refresh_token');
        $expiresIn = $response->json('expires_in');

        return new OAuthGrant(
            accessToken: $token,
            expiresIn: is_numeric($expiresIn) ? (int) $expiresIn : 3600,
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            scopes: GoogleScopes::parse(is_string($response->json('scope')) ? $response->json('scope') : ''),
        );
    }

    /**
     * RFC 6749 §5.2 errors: {"error": "invalid_grant", "error_description": "..."}.
     */
    private static function grantError(string $what, Response $response): GoogleApiException
    {
        $status = $response->status();
        $error = $response->json('error');
        $code = is_string($error) ? $error : null;
        $description = $response->json('error_description');

        $kind = match (true) {
            $code === 'invalid_grant' => GoogleApiException::INVALID_GRANT,
            $code === 'invalid_client', $code === 'unauthorized_client' => GoogleApiException::NOT_CONFIGURED,
            $status === 429 || $status >= 500 => GoogleApiException::UNAVAILABLE,
            default => GoogleApiException::BAD_REQUEST,
        };

        return new GoogleApiException(
            $kind,
            trim("{$what} failed: HTTP {$status} ".($code ?? '')),
            $status,
            is_string($description) ? mb_substr($description, 0, 200) : null,
        );
    }

    private static function timeout(): int
    {
        return max(1, (int) config('services.google.timeout', 20));
    }
}
