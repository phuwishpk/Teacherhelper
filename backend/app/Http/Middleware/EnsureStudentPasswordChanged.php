<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Impersonations;
use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * DESIGN §29.10: a student whose password is still the initial one (a new
 * account, or after the teacher's reset) may only read /me and change the
 * password; everything else answers 403 password_change_required.
 * An admin's "เข้าใช้แทน" session (§29.14) is let through: the admin came
 * to look, not to choose the student's password.
 */
class EnsureStudentPasswordChanged
{
    private const OPEN = ['api.me', 'api.student.password'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && $user->isStudent() && ! $request->routeIs(...self::OPEN) && self::mustChange($user)) {
            throw new ApiException('ตั้งรหัสผ่านใหม่ก่อนใช้งาน', 'password_change_required', 403);
        }

        return $next($request);
    }

    /** The student must set a password before anything else (never in an admin's impersonation session). */
    public static function mustChange(User $user): bool
    {
        if (! $user->isStudent() || ! $user->credential?->must_change_password) {
            return false;
        }
        $token = $user->currentAccessToken();

        return ! ($token instanceof PersonalAccessToken && Impersonations::adminIdOf($token) !== null);
    }
}
