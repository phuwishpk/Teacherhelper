<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Google\GoogleSignInConfig;
use App\Domain\Auth\Google\GoogleSignInErrors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Google sign-in routes (DESIGN §24.12 C) on a server without
 * GOOGLE_SIGNIN_CLIENT_IDS: 503 google_signin_not_configured before any
 * other precondition. On the routes that need a token it runs after auth
 * and role, so guests still get 401. GET /auth/google/config stays open and
 * answers `enabled: false`. Alias `google.signin` in bootstrap/app.php.
 */
class EnsureGoogleSignInConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! GoogleSignInConfig::enabled()) {
            throw GoogleSignInErrors::notConfigured();
        }

        return $next($request);
    }
}
