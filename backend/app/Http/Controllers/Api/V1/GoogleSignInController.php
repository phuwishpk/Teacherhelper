<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\Google\GoogleIdTokenVerifier;
use App\Domain\Auth\Google\GoogleSignIn;
use App\Domain\Auth\Google\GoogleSignInConfig;
use App\Domain\Auth\Google\GoogleSignInErrors;
use App\Domain\Auth\Google\GoogleSignInTickets;
use App\Domain\Auth\Google\GoogleSignInWeb;
use App\Domain\Auth\Google\VerifiedGoogleIdentity;
use App\Domain\Students\StudentAuthenticator;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

/**
 * Google sign-in without a token (DESIGN §24.9, §24.12 C): the public
 * config, sign-in with an ID token, the browser flow's URL and ticket, and
 * the student's first confirmation with PIN or QR. Every route but config
 * sits behind `google.signin` (503 google_signin_not_configured).
 */
class GoogleSignInController extends Controller
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $verifier,
        private readonly GoogleSignIn $signIn,
        private readonly GoogleSignInTickets $tickets,
    ) {}

    /** GET /auth/google/config -> {data: {enabled, web_flow, notice_version}} (never 503). */
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => GoogleSignInConfig::enabled(),
            'web_flow' => GoogleSignInConfig::webFlowEnabled(),
            'notice_version' => GoogleSignInConfig::NOTICE_VERSION,
        ]]);
    }

    /**
     * POST /auth/google {id_token, intent: staff|student, device_name?} ->
     * {token, user} | 404 google_not_linked {link_ticket?, registration?}.
     */
    public function signIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
            'intent' => ['required', 'string', 'in:staff,student'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], self::messages());

        $google = $this->verifier->verify($data['id_token']);

        return self::tokenResponse($this->signIn->signIn($google, $data['intent'], $data['device_name'] ?? null));
    }

    /**
     * POST /auth/google/web-url {purpose: login|link, intent?, accept_notice?}
     * -> {data: {url}}. `link` needs the user's bearer token (401 without);
     * a student must accept the notice first (422 notice_required).
     */
    public function webUrl(Request $request, GoogleSignInWeb $web): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', 'in:login,link'],
            'intent' => ['sometimes', 'nullable', 'string', 'in:staff,student'],
            'accept_notice' => ['sometimes', 'boolean'],
        ], self::messages());
        if (! GoogleSignInConfig::webFlowEnabled()) {
            throw GoogleSignInErrors::webNotConfigured();
        }

        $userId = null;
        if ($data['purpose'] === 'link') {
            $user = $request->user('sanctum');
            if (! $user instanceof User || $user->currentAccessToken() === null || ! $user->tokenCan($user->role)) {
                throw new AuthenticationException;
            }
            if (! $user->isActive()) {
                throw GoogleSignInErrors::accountNotActive($user->status);
            }
            if (! GoogleSignIn::canLink($user)) {
                throw GoogleSignInErrors::studentDisabled();
            }
            if ($user->isStudent() && ! ($data['accept_notice'] ?? false)) {
                throw GoogleSignInErrors::noticeRequired();
            }
            $userId = (int) $user->id;
        }

        $nonce = GoogleSignInTickets::random32();
        $state = $this->tickets->issueState([
            'purpose' => $data['purpose'],
            'intent' => ($data['intent'] ?? null) === GoogleSignIn::INTENT_STUDENT ? GoogleSignIn::INTENT_STUDENT : GoogleSignIn::INTENT_STAFF,
            'nonce' => $nonce,
            'user_id' => $userId,
            'accept_notice' => (bool) ($data['accept_notice'] ?? false),
        ]);

        return response()->json(['data' => ['url' => $web->authorizationUrl($state, $nonce)]]);
    }

    /** POST /auth/google/ticket {ticket, device_name?} -> the answer of POST /auth/google. */
    public function ticket(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket' => ['required', 'string', 'max:64'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], self::messages());

        $login = $this->tickets->consumeLogin($data['ticket']);
        if ($login === null) {
            throw GoogleSignInErrors::ticketInvalid();
        }

        return self::tokenResponse($this->signIn->signIn($login['identity'], $login['intent'], $data['device_name'] ?? null));
    }

    /**
     * POST /auth/google/link-with-pin {link_ticket, class_code, student_number, pin, accept_notice, device_name?}
     * -> {token, user}: the PIN login's checks (generic error, lockout), then the link (pin_confirm).
     */
    public function linkWithPin(Request $request, StudentAuthenticator $students): JsonResponse
    {
        $data = $request->validate([
            'link_ticket' => ['required', 'string', 'max:64'],
            'class_code' => ['required', 'string', 'max:12'],
            'student_number' => ['required', 'integer', 'min:1', 'max:255'],
            'pin' => ['required', 'string', 'digits:6'],
            'accept_notice' => ['sometimes', 'boolean'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], self::messages());
        $google = $this->ticketIdentity($data);

        $student = $students->studentByPin($data['class_code'], (int) $data['student_number'], $data['pin']);

        return self::tokenResponse($this->signIn->linkStudentWithTicket($student, $google, $data['link_ticket'], $data['device_name'] ?? null));
    }

    /** POST /auth/google/link-with-qr {link_ticket, qr_token, accept_notice, device_name?} -> {token, user}. */
    public function linkWithQr(Request $request, StudentAuthenticator $students): JsonResponse
    {
        $data = $request->validate([
            'link_ticket' => ['required', 'string', 'max:64'],
            'qr_token' => ['required', 'string', 'max:128'],
            'accept_notice' => ['sometimes', 'boolean'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], self::messages());
        $google = $this->ticketIdentity($data);

        $student = $students->studentByQr($data['qr_token']);

        return self::tokenResponse($this->signIn->linkStudentWithTicket($student, $google, $data['link_ticket'], $data['device_name'] ?? null));
    }

    /**
     * The notice must be accepted (422 notice_required) and the link ticket
     * still valid (422 link_ticket_invalid) before the PIN is even checked;
     * the ticket is spent only once the link succeeds.
     *
     * @param  array<string, mixed>  $data
     */
    private function ticketIdentity(array $data): VerifiedGoogleIdentity
    {
        if (! ($data['accept_notice'] ?? false)) {
            throw GoogleSignInErrors::noticeRequired();
        }
        $google = $this->tickets->peekLink($data['link_ticket']);
        if ($google === null) {
            throw GoogleSignInErrors::linkTicketInvalid();
        }

        return $google;
    }

    /** @param  array{token: NewAccessToken, user: User}  $result */
    private static function tokenResponse(array $result): JsonResponse
    {
        return response()->json([
            'token' => $result['token']->plainTextToken,
            'user' => new UserResource($result['user']->loadMissing('school')),
        ]);
    }

    /** @return array<string, string> */
    private static function messages(): array
    {
        return [
            'id_token.required' => 'ไม่ได้รับข้อมูลยืนยันจาก Google',
            'intent.required' => 'กรุณาระบุว่าเข้าสู่ระบบเป็นครูหรือนักเรียน',
            'intent.in' => 'กรุณาระบุว่าเข้าสู่ระบบเป็นครูหรือนักเรียน',
            'purpose.required' => 'กรุณาระบุ purpose',
            'purpose.in' => 'purpose ต้องเป็น login หรือ link',
            'ticket.required' => 'ไม่ได้รับรหัสเข้าสู่ระบบ',
            'link_ticket.required' => 'ไม่ได้รับรหัสยืนยันบัญชี Google',
            'class_code.required' => 'กรุณากรอกรหัสห้อง',
            'student_number.required' => 'กรุณากรอกเลขที่',
            'student_number.integer' => 'เลขที่ต้องเป็นตัวเลข',
            'pin.required' => 'กรุณากรอก PIN',
            'pin.digits' => 'PIN ต้องเป็นตัวเลข 6 หลัก',
            'qr_token.required' => 'กรุณาสแกนบัตร QR',
        ];
    }
}
