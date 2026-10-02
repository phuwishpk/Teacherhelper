<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\Google\GoogleIdTokenVerifier;
use App\Domain\Auth\Google\GoogleSignIn;
use App\Domain\Auth\Google\GoogleSignInConfig;
use App\Domain\Auth\Google\GoogleSignInErrors;
use App\Domain\Auth\Google\GoogleSignInTickets;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The Google account a user signs in with (DESIGN §24.9.5, §24.12 C):
 * every role (teacher, student, admin) reads, links and unlinks their own
 * under /me/google-identity, and a student's editors (§24.2) unlink theirs.
 */
class GoogleIdentityController extends Controller
{
    public function __construct(private readonly GoogleSignIn $signIn) {}

    /**
     * GET /me/google-identity -> {data: {linked, email, name, picture_url,
     * linked_via, linked_at, can_link, notice_version}}.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::payload($request->user())]);
    }

    /**
     * POST /me/google-identity {id_token, accept_notice} -> {data}: links the
     * verified Google account (`self`). A student must accept the notice
     * (422 notice_required); 409 google_already_linked / google_identity_exists,
     * 403 google_domain_not_allowed / student_google_disabled.
     */
    public function store(Request $request, GoogleIdTokenVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
            'accept_notice' => ['sometimes', 'boolean'],
        ], ['id_token.required' => 'ไม่ได้รับข้อมูลยืนยันจาก Google']);
        /** @var User $user */
        $user = $request->user()->loadMissing('school');
        if (! GoogleSignIn::canLink($user)) {
            throw GoogleSignInErrors::studentDisabled();
        }
        if ($user->isStudent() && ! ($data['accept_notice'] ?? false)) {
            throw GoogleSignInErrors::noticeRequired();
        }

        $this->signIn->link($user, $verifier->verify($data['id_token']), UserGoogleIdentity::VIA_SELF, $user);

        return response()->json(['data' => self::payload($user)]);
    }

    /**
     * POST /me/google-identity/ticket {ticket} -> {data}: finishes a link
     * started in the browser (DESIGN §24.9.4). The callback only hands over a
     * ticket; the account is linked here, with the token of the user the
     * ticket was made for. A ticket of another user is spent and refused
     * (422 google_ticket_invalid), so a Google URL passed to somebody else
     * links nothing. The notice was accepted when the URL was made.
     */
    public function storeFromTicket(Request $request, GoogleSignInTickets $tickets): JsonResponse
    {
        $data = $request->validate(
            ['ticket' => ['required', 'string', 'max:128']],
            ['ticket.required' => 'ไม่ได้รับลิงก์เชื่อมบัญชี Google'],
        );
        /** @var User $user */
        $user = $request->user()->loadMissing('school');
        $link = $tickets->consumeWebLink($data['ticket']);
        if ($link === null || $link['user_id'] !== $user->id) {
            GoogleSignIn::log('web_link', $link === null ? 'ticket_invalid' : 'ticket_other_user', $user->id);

            throw GoogleSignInErrors::webLinkTicketInvalid();
        }
        if (! GoogleSignIn::canLink($user)) {
            throw GoogleSignInErrors::studentDisabled();
        }

        $this->signIn->link($user, $link['identity'], UserGoogleIdentity::VIA_SELF, $user);

        return response()->json(['data' => self::payload($user)]);
    }

    /**
     * DELETE /me/google-identity -> 204 (also when nothing was linked).
     * 409 google_unlink_needs_password for a teacher or admin without a
     * password (an account made by Google sign-up, #71): unlinking would
     * leave no way to sign in, so an admin sets a password first.
     */
    public function destroy(Request $request): Response
    {
        $user = $request->user();
        if (! $user->isStudent() && $user->password === null && $user->googleIdentity()->exists()) {
            GoogleSignIn::log('unlink', 'google_unlink_needs_password', $user->id);

            throw GoogleSignInErrors::unlinkNeedsPassword();
        }
        $this->signIn->unlink($user, $user, 'self');

        return response()->noContent();
    }

    /**
     * DELETE /students/{id}/google-identity -> 204: the student's editors
     * (homeroom teacher of an open classroom of theirs, §24.2); a colleague
     * who sees the student gets 403 not_homeroom_teacher, others 404.
     */
    public function destroyForStudent(Request $request, int $id): Response
    {
        $student = StudentController::schoolStudent($request, $id);
        if ($student->isMerged() || ! Gate::allows('editStudent', $student)) {
            throw StudentController::notHomeroom();
        }
        $this->signIn->unlink($student, $request->user(), 'editor');

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    public static function payload(User $user): array
    {
        $identity = UserGoogleIdentity::query()->where('user_id', $user->id)->first();

        return [
            'linked' => $identity !== null,
            'email' => $identity?->email,
            'name' => $identity?->name,
            'picture_url' => $identity?->picture_url,
            'linked_via' => $identity?->linked_via,
            'linked_at' => $identity?->linked_at?->toIso8601String(),
            'can_link' => GoogleSignIn::canLink($user->loadMissing('school')),
            'notice_version' => GoogleSignInConfig::NOTICE_VERSION,
        ];
    }
}
