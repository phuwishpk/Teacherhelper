<?php

namespace App\Http\Controllers;

use App\Domain\Google\GoogleAccounts;
use App\Domain\Google\GoogleOAuth;
use App\Domain\Google\GoogleOAuthStates;
use App\Domain\Google\GoogleScopes;
use App\Exceptions\ApiException;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * GET /google/oauth/callback: where Google sends the teacher's browser back
 * after the consent page of the browser connect flow (POST
 * /api/v1/google/oauth/url). A web route without login: the single-use
 * `state` (GoogleOAuthStates) says which teacher asked, and is spent on the
 * first visit whatever happens next.
 *
 * The code is exchanged with the same redirect URI and stored exactly like
 * POST /google/connect (GoogleAccounts::connect: scope check, encrypted
 * refresh token, userProfiles/me). The answer is a small Thai page; the app
 * notices the connection by polling GET /google/status. No code, state,
 * token or secret goes into the page, the log or a redirect.
 */
class GoogleOAuthCallbackController extends Controller
{
    public function __invoke(Request $request, GoogleOAuthStates $states, GoogleAccounts $accounts): Response
    {
        $state = $request->query('state');
        $teacherId = $states->consume(is_string($state) ? $state : null);

        if (! GoogleOAuth::isConfigured()) {
            return $this->page(503, 'error', 'ยังเชื่อม Google Classroom ไม่ได้',
                'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่า Google Classroom (GOOGLE_OAUTH_CLIENT_ID / GOOGLE_OAUTH_CLIENT_SECRET) กรุณาแจ้งผู้ดูแลระบบ');
        }

        $error = $request->query('error');
        if (is_string($error) && $error !== '') {
            // Google's codes are lower_snake_case; show and log nothing else.
            $error = preg_match('/^[a-z_]{1,40}/', $error, $m) === 1 ? $m[0] : '';
            Log::info('google.oauth_callback', ['result' => 'google_error', 'error' => $error, 'user_id' => $teacherId]);

            return $error === 'access_denied'
                ? $this->page(200, 'cancelled', 'ยกเลิกการเชื่อม Google Classroom แล้ว',
                    'ยังไม่ได้อนุญาตสิทธิ์ใน Google จึงไม่ได้เชื่อมบัญชี ถ้าต้องการเชื่อม กลับไปที่แอป Krucheck แล้วกด "เชื่อม Google Classroom" อีกครั้ง')
                : $this->page(400, 'error', 'Google ไม่ได้ให้สิทธิ์',
                    'Google ส่งกลับมาโดยไม่มีรหัสยืนยัน'.($error !== '' ? " ({$error})" : '').' กลับไปที่แอป Krucheck แล้วกดเชื่อมอีกครั้ง');
        }

        if ($teacherId === null) {
            Log::info('google.oauth_callback', ['result' => 'state_invalid']);

            return $this->page(400, 'error', 'ลิงก์เชื่อม Google ใช้ไม่ได้แล้ว',
                'ลิงก์นี้ถูกใช้ไปแล้วหรือเปิดไว้นานเกิน 10 นาที กลับไปที่แอป Krucheck แล้วกด "เชื่อม Google Classroom" อีกครั้ง');
        }

        $teacher = User::query()->find($teacherId);
        if ($teacher === null || Gate::forUser($teacher)->denies('manage', GoogleAccount::class)) {
            Log::info('google.oauth_callback', ['result' => 'forbidden', 'user_id' => $teacherId]);

            return $this->page(403, 'error', 'เชื่อม Google Classroom ไม่ได้',
                'บัญชีครูที่ขอเชื่อมใช้งานไม่ได้แล้ว (ถูกระงับหรือไม่ใช่บัญชีครู) กรุณาแจ้งผู้ดูแลระบบ');
        }

        $code = $request->query('code');
        if (! is_string($code) || preg_match('/^[\x21-\x7E]{10,2048}$/', $code) !== 1) {
            Log::info('google.oauth_callback', ['result' => 'code_missing', 'user_id' => $teacher->id]);

            return $this->page(400, 'error', 'Google ไม่ได้ส่งรหัสยืนยันมา',
                'กลับไปที่แอป Krucheck แล้วกด "เชื่อม Google Classroom" อีกครั้ง');
        }

        try {
            $status = $accounts->connect($teacher, $code, GoogleOAuth::redirectUri());
        } catch (ApiException $e) {
            Log::info('google.oauth_callback', ['result' => $e->errorCode, 'user_id' => $teacher->id]);
            $missing = $e->errorCode === 'google_scope_missing' ? ($e->errors['scopes'] ?? []) : [];

            return $this->page($e->status, 'error', 'เชื่อม Google Classroom ไม่สำเร็จ', $e->getMessage(),
                missing: array_map(GoogleScopes::label(...), array_values($missing)));
        }

        return $this->page(200, 'success', 'เชื่อม Google Classroom สำเร็จ',
            'กลับไปที่แอป Krucheck ได้เลย แอปจะเห็นการเชื่อมเองในไม่กี่วินาที (หรือกด "ตรวจสอบการเชื่อม") แล้วปิดหน้านี้ได้',
            email: (string) ($status['email'] ?? ''), teacher: (string) $teacher->name);
    }

    /**
     * @param  'success'|'cancelled'|'error'  $kind
     * @param  list<string>  $missing  Thai labels of scopes that were not granted
     */
    private function page(int $status, string $kind, string $title, string $message, array $missing = [], ?string $email = null, ?string $teacher = null): Response
    {
        return response()
            ->view('google.oauth-result', compact('kind', 'title', 'message', 'missing', 'email', 'teacher'), $status)
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            ]);
    }
}
