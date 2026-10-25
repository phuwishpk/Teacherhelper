<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AdminHandoffs;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GET /admin/handoff/{token}: the one-time link of POST
 * /api/v1/auth/admin-handoff (DESIGN §7.4, §7.5). A valid token signs the
 * browser into the web guard of the Filament panel and opens /admin; an
 * unknown, expired or used token, or an account that may not open the
 * panel any more, goes to /admin/login with a Thai message. The token is
 * spent on the first visit whatever happens next.
 *
 * No-referrer, so the link never leaks to the next page in a Referer header.
 */
class AdminHandoffLinkController extends Controller
{
    public const ERROR_KEY = 'admin_handoff_error';

    public function __invoke(Request $request, string $token, AdminHandoffs $handoffs): RedirectResponse
    {
        $panel = Filament::getPanel('admin');
        $userId = $handoffs->consume($token);
        $user = $userId === null ? null : User::query()->find($userId);

        if ($user === null || ! $user->canAccessPanel($panel)) {
            Log::info('admin.handoff', ['result' => $userId === null ? 'token_invalid' : 'not_allowed', 'user_id' => $userId]);

            return $this->noReferrer(redirect()->to($panel->getLoginUrl() ?? url('/admin/login'))->with(
                self::ERROR_KEY,
                'ลิงก์เข้าสู่ระบบนี้ใช้ไม่ได้แล้ว (หมดอายุภายใน 1 นาทีและใช้ได้ครั้งเดียว) กลับไปที่แอปแล้วกด "เปิดหน้าผู้ดูแลระบบ" อีกครั้ง หรือเข้าสู่ระบบด้านล่าง',
            ));
        }

        $panel->auth()->login($user);
        $request->session()->regenerate();
        Log::info('admin.handoff', ['result' => 'ok', 'user_id' => $user->id]);

        return $this->noReferrer(redirect()->to($panel->getUrl() ?? url('/admin')));
    }

    private function noReferrer(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
