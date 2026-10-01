<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\Google\GoogleSignIn;
use App\Domain\Students\CredentialIssuer;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/students/{id}/pin {keep_google?: bool} -> 200
 * {student_id, pin, google_unlinked} (DESIGN §9.2, §24.9.5).
 * Resets the PIN, clears any lockout and revokes the student's sessions.
 * The plain PIN is returned once and never stored.
 *
 * A reset also removes the student's Google sign-in link unless the teacher
 * sends keep_google=true: a PIN is usually reset because someone else may
 * know it, and that person could have used it to link their own Google
 * account (link-with-pin) and keep signing in after the reset.
 */
class StudentPinController extends Controller
{
    public function __construct(
        private readonly CredentialIssuer $issuer,
        private readonly GoogleSignIn $google,
    ) {}

    public function store(Request $request, int $id): JsonResponse
    {
        // Scoped to the caller's school first: another school's student is a
        // 404 (not a 403 that would confirm the id exists); a colleague's is 403.
        $student = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('school_id', $request->user()->school_id)
            ->findOrFail($id);
        Gate::authorize('manageStudentCredentials', $student);
        $keepGoogle = $request->validate(['keep_google' => ['sometimes', 'boolean']])['keep_google'] ?? false;

        $pin = $this->issuer->issuePin($student);
        $unlinked = $keepGoogle ? false : $this->google->unlink($student, $request->user(), 'pin_reset');

        return response()->json([
            'student_id' => $student->id,
            'pin' => $pin,
            'google_unlinked' => $unlinked,
        ]);
    }
}
