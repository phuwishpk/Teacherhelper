<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `role:teacher`, `role:student` or `role:teacher,student` (DESIGN §7.4): the
 * account's role AND the token's ability must both match. Login issues a
 * token with the ability of the account's role, so this is belt and braces:
 * a token that somehow carries the other ability never opens the other
 * role's routes, and the policies behind it check the role again.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if ($user === null || $user->currentAccessToken() === null) {
            throw new AuthenticationException;
        }

        foreach ($roles as $role) {
            if ($user->role === $role && $user->tokenCan($role)) {
                return $next($request);
            }
        }

        throw new AuthorizationException;
    }
}
