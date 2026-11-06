<?php

namespace App\Domain\Auth\Google;

use App\Domain\Google\GoogleOAuth;
use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The browser flow of Google sign-in (DESIGN §24.9.4), used by the Flutter
 * web UI preview, where google_sign_in's authenticate() does not exist:
 * Google's account chooser with openid email profile only (no offline
 * access, so no refresh token), then the code is exchanged with the Web
 * client's secret for an ID token that goes through GoogleIdTokenVerifier
 * with the state's nonce. The access token Google returns is dropped.
 */
final class GoogleSignInWeb
{
    public const SCOPES = 'openid email profile';

    public function authorizationUrl(string $state, string $nonce): string
    {
        return GoogleOAuth::AUTH_URL.'?'.http_build_query([
            'client_id' => GoogleSignInConfig::webClientId(),
            'redirect_uri' => GoogleSignInConfig::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'prompt' => 'select_account',
            'state' => $state,
            'nonce' => $nonce,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The ID token of a code of the account chooser.
     *
     * @throws ApiException 503 google_unavailable, 422 google_token_invalid
     */
    public function exchangeCode(#[\SensitiveParameter] string $code): string
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(GoogleSignInConfig::timeout())->post(GoogleOAuth::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => GoogleSignInConfig::redirectUri(),
                'client_id' => GoogleSignInConfig::webClientId(),
                'client_secret' => GoogleSignInConfig::clientSecret(),
            ]);
        } catch (ConnectionException) {
            throw GoogleSignInErrors::unavailable();
        }

        if ($response->serverError() || $response->status() === 429) {
            throw GoogleSignInErrors::unavailable();
        }
        $idToken = $response->successful() ? $response->json('id_token') : null;
        if (! is_string($idToken) || $idToken === '') {
            GoogleSignIn::log('web_callback', 'code_exchange_failed');

            throw GoogleSignInErrors::tokenInvalid();
        }

        return $idToken;
    }
}
