<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DESIGN §29.10: a student whose password is still the initial one (a new
 * account, or after the teacher's reset) may only read /me and change the
 * password; everything else answers 403 password_change_required.
 */
class EnsureStudentPasswordChanged
{
    private const OPEN = ['api.me', 'api.student.password'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && $user->isStudent() && ! $request->routeIs(...self::OPEN) && $user->credential?->must_change_password) {
            throw new ApiException('ตั้งรหัสผ่านใหม่ก่อนใช้งาน', 'password_change_required', 403);
        }

        return $next($request);
    }
}
