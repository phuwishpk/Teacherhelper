<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Students\CredentialIssuer;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/students/{id}/pin -> 200 {student_id, pin} (DESIGN §9.2).
 * Resets the PIN, clears any lockout and revokes the student's sessions.
 * The plain PIN is returned once and never stored.
 */
class StudentPinController extends Controller
{
    public function __construct(private readonly CredentialIssuer $issuer) {}

    public function store(Request $request, int $id): JsonResponse
    {
        // Scoped to the caller's school first: another school's student is a
        // 404 (not a 403 that would confirm the id exists); a colleague's is 403.
        $student = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('school_id', $request->user()->school_id)
            ->findOrFail($id);
        Gate::authorize('manageStudentCredentials', $student);

        $pin = $this->issuer->issuePin($student);

        return response()->json([
            'student_id' => $student->id,
            'pin' => $pin,
        ]);
    }
}
