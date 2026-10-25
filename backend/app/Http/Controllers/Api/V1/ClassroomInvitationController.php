<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\CoTeachers;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The invited teacher's side of co-teaching (DESIGN §29.13.4): the
 * invitations addressed to their e-mail, accepted or declined.
 */
class ClassroomInvitationController extends Controller
{
    public function __construct(private readonly CoTeachers $coTeachers) {}

    /** GET /api/v1/classroom-invitations -> {data: [{id, classroom: {id, name, grade_level, academic_year}, owner: {id, name}, invited_at}]} */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Classroom::class);

        return response()->json(['data' => $this->coTeachers->incoming($request->user())
            ->map(fn ($row) => CoTeachers::incomingPayload($row))->values()->all()]);
    }

    /** POST /api/v1/classroom-invitations/{id}/accept -> {data: row}: the teacher co-teaches the classroom from now on. 409 classroom_closed */
    public function accept(Request $request, int $id): JsonResponse
    {
        Gate::authorize('viewAny', Classroom::class);
        $row = CoTeachers::invitationFor($request->user(), $id);

        return response()->json(['data' => CoTeachers::incomingPayload($this->coTeachers->accept($request->user(), $row))]);
    }

    /** POST /api/v1/classroom-invitations/{id}/decline -> {data: row} */
    public function decline(Request $request, int $id): JsonResponse
    {
        Gate::authorize('viewAny', Classroom::class);
        $row = CoTeachers::invitationFor($request->user(), $id);

        return response()->json(['data' => CoTeachers::incomingPayload($this->coTeachers->decline($request->user(), $row))]);
    }
}
