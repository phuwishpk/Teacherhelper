<?php

namespace App\Http\Middleware;

use App\Domain\Google\GoogleErrors;
use App\Domain\Google\GoogleOAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Google Classroom routes (DESIGN §18.6) on a server without the OAuth client
 * (GOOGLE_OAUTH_CLIENT_ID / _SECRET): answer 503 google_not_configured before
 * any other precondition (classroom_not_linked, not_posted, ...), so the app
 * says Google is not set up instead of asking the teacher to link a course.
 * Runs after auth and role, so guests still get 401 and students 403.
 * GET /google/status stays open: it reports `configured` (the app hides its
 * Classroom UI when false). The browser flow's GET /google/oauth/callback is
 * a web route and shows its own page instead.
 * Alias `google.configured` in bootstrap/app.php.
 */
class EnsureGoogleConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! GoogleOAuth::isConfigured()) {
            throw GoogleErrors::notConfigured();
        }

        return $next($request);
    }
}
