<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token stays in personal_access_tokens after an admin disables the account
 * (Filament revokes them too, but a token issued between the two checks or a
 * status changed by hand must still fail). Alias `active` in bootstrap/app.php.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            throw new ApiException(
                $user->status === User::STATUS_PENDING
                    ? 'บัญชีของคุณกำลังรอการอนุมัติจากผู้ดูแลระบบ'
                    : 'บัญชีของคุณถูกระงับการใช้งาน',
                'account_not_active',
                403,
            );
        }

        return $next($request);
    }
}
