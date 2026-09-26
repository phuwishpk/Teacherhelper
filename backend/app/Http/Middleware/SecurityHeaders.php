<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers every route gets (API and the Filament panel), so a
 * browser never sniffs a crop or PDF into HTML, never frames the admin from
 * another site and never caches an API answer that carries a token, a score
 * or a student's image. HSTS only over HTTPS (Plesk: Let's Encrypt with the
 * http-to-https redirect, docs/HOSTING.md); a plain-http dev server never
 * pins anything.
 */
class SecurityHeaders
{
    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        // PHP adds X-Powered-By with its version at SAPI level (expose_php);
        // drop it here as well as in the Plesk PHP settings (docs/HOSTING.md).
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', self::HSTS);
        }

        if ($request->is('api/*')) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
            // Symfony's default is "no-cache, private"; the API wants no-store
            // unless the controller chose its own policy (file downloads).
            if (! $headers->has('Cache-Control') || $headers->get('Cache-Control') === 'no-cache, private') {
                $headers->set('Cache-Control', 'no-store, private');
            }
            if (str_starts_with((string) $headers->get('Content-Type'), 'application/json')) {
                $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
            }
        }

        return $response;
    }
}
