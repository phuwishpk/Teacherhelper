<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Google\GoogleIdTokenVerifier;
use App\Domain\Auth\Google\GoogleSignIn;
use App\Domain\Auth\Google\GoogleSignInConfig;
use App\Domain\Auth\Google\GoogleSignInTickets;
use App\Domain\Auth\Google\GoogleSignInWeb;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /auth/google/callback (DESIGN §24.9.4): where Google's account
 * chooser of the browser sign-in flow returns. A web route without login or
 * session: the single-use state (GoogleSignInTickets) holds the purpose,
 * intent, nonce and, for `link`, the user. The state is spent first,
 * whatever happens next.
 *
 * - login: a 60-second single-use ticket, redirected to
 *   GOOGLE_SIGNIN_APP_URL/#/login/google?ticket=... (the fragment never
 *   reaches a server); the web app redeems it with POST /auth/google/ticket.
 *   Failures go to /#/login/google?error=<code>.
 * - link: links the account to the state's user and redirects to
 *   /#/google-link?status=linked or ?status=<error code>.
 *
 * The redirect target comes from .env only (no open redirect). An unknown,
 * expired or spent state is a Thai page with 400. Every response carries
 * Referrer-Policy: no-referrer and Cache-Control: no-store; no code, token
 * or e-mail reaches the log, a page or a redirect.
 */
class GoogleSignInCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        GoogleSignInTickets $tickets,
        GoogleSignInWeb $web,
        GoogleIdTokenVerifier $verifier,
        GoogleSignIn $signIn,
    ): Response|RedirectResponse {
        $state = $request->query('state');
        $data = $tickets->consumeState(is_string($state) ? $state : null);

        if (! GoogleSignInConfig::webFlowEnabled()) {
            GoogleSignIn::log('web_callback', 'not_configured');

            return $this->page(503, 'ยังเข้าสู่ระบบด้วย Google ทางเว็บไม่ได้', 'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่าการเข้าสู่ระบบด้วย Google ทางเว็บ กรุณาแจ้งผู้ดูแลระบบ');
        }
        if ($data === null) {
            GoogleSignIn::log('web_callback', 'state_invalid');

            return $this->page(400, 'ลิงก์เข้าสู่ระบบด้วย Google ใช้ไม่ได้แล้ว', 'ลิงก์นี้ถูกใช้ไปแล้วหรือเปิดไว้นานเกิน 10 นาที กลับไปที่ EduVision แล้วกดปุ่ม Google อีกครั้ง');
        }

        $isLink = $data['purpose'] === 'link';
        $userId = is_numeric($data['user_id'] ?? null) ? (int) $data['user_id'] : null;

        $error = $request->query('error');
        if (is_string($error) && $error !== '') {
            GoogleSignIn::log('web_callback', $error === 'access_denied' ? 'cancelled' : 'google_error', $userId);

            return $this->back($isLink, $error === 'access_denied' ? 'cancelled' : 'google_error');
        }
        $code = $request->query('code');
        if (! is_string($code) || preg_match('/^[\x21-\x7E]{10,2048}$/', $code) !== 1) {
            GoogleSignIn::log('web_callback', 'code_missing', $userId);

            return $this->back($isLink, 'google_token_invalid');
        }

        try {
            $google = $verifier->verify($web->exchangeCode($code), (string) $data['nonce']);

            if (! $isLink) {
                GoogleSignIn::log('web_callback', 'login_ticket');

                $schoolId = is_int($data['school_id'] ?? null) ? $data['school_id'] : null;

                return $this->redirect('/#/login/google?ticket='.$tickets->issueLogin($google, (string) $data['intent'], $schoolId));
            }

            $user = $userId === null ? null : User::query()->with('school')->find($userId);
            if ($user === null || ! $user->isActive() || $user->isMerged()) {
                GoogleSignIn::log('web_callback', 'account_not_active', $userId);

                return $this->back(true, 'account_not_active');
            }
            if ($user->isStudent() && ! ($data['accept_notice'] ?? false)) {
                return $this->back(true, 'notice_required');
            }
            $signIn->link($user, $google, UserGoogleIdentity::VIA_SELF, $user);

            return $this->redirect('/#/google-link?status=linked');
        } catch (ApiException $e) {
            GoogleSignIn::log('web_callback', $e->errorCode, $userId);

            return $this->back($isLink, $e->errorCode);
        }
    }

    private function back(bool $isLink, string $code): RedirectResponse
    {
        $code = preg_match('/^[a-z_]{1,60}$/', $code) === 1 ? $code : 'google_error';

        return $this->redirect($isLink ? '/#/google-link?status='.$code : '/#/login/google?error='.$code);
    }

    private function redirect(string $path): RedirectResponse
    {
        return redirect()->away(GoogleSignInConfig::appUrl().$path)->withHeaders(self::headers());
    }

    private function page(int $status, string $title, string $message): Response
    {
        return response()
            ->view('google.signin-result', compact('title', 'message'), $status)
            ->withHeaders([
                ...self::headers(),
                'X-Robots-Tag' => 'noindex, nofollow',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            ]);
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store, private'];
    }
}
