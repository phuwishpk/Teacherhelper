<?php

namespace App\Domain\Auth\Google;

/**
 * The settings of Google sign-in (config('services.google_signin'), DESIGN
 * §24.9.1). Sign-in is on when at least one client ID is set; the browser
 * flow also needs the Web client's secret and the web app's URL.
 */
final class GoogleSignInConfig
{
    /** The PDPA notice shown before linking (DESIGN §24.14); change it together with the text. */
    public const NOTICE_VERSION = 'gsi-1';

    /** @return list<string> the accepted `aud` values; the first is the Web client */
    public static function clientIds(): array
    {
        $raw = config('services.google_signin.client_ids');
        $ids = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(fn ($id) => trim((string) $id), $ids), fn (string $id) => $id !== ''));
    }

    public static function enabled(): bool
    {
        return self::clientIds() !== [];
    }

    public static function webFlowEnabled(): bool
    {
        return self::enabled()
            && trim((string) config('services.google_signin.client_secret')) !== ''
            && self::appUrl() !== '';
    }

    public static function webClientId(): string
    {
        return self::clientIds()[0] ?? '';
    }

    public static function clientSecret(): string
    {
        return trim((string) config('services.google_signin.client_secret'));
    }

    /** GOOGLE_SIGNIN_REDIRECT_URI, or APP_URL + /auth/google/callback. */
    public static function redirectUri(): string
    {
        $configured = trim((string) config('services.google_signin.redirect_uri'));

        return $configured !== '' ? $configured : rtrim((string) config('app.url'), '/').'/auth/google/callback';
    }

    /** The web app the callback redirects to; from .env only (no open redirect). */
    public static function appUrl(): string
    {
        return rtrim(trim((string) config('services.google_signin.app_url')), '/');
    }

    /** Seconds an ID token is accepted after its `iat`. */
    public static function maxAge(): int
    {
        return max(60, (int) config('services.google_signin.max_age', 600));
    }

    public static function timeout(): int
    {
        return max(1, (int) config('services.google.timeout', 20));
    }
}
