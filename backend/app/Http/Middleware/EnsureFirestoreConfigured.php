<?php

namespace App\Http\Middleware;

use App\Domain\Firestore\FirestoreConfig;
use App\Domain\Firestore\FirestoreErrors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes that need the Firestore live layer (DESIGN §38.4) on a server with
 * FIRESTORE_ENABLED=false: 503 firestore_not_configured. Runs after auth and
 * role, so guests still get 401. Alias `firestore` in bootstrap/app.php.
 */
class EnsureFirestoreConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! FirestoreConfig::enabled()) {
            throw FirestoreErrors::notConfigured();
        }

        return $next($request);
    }
}
