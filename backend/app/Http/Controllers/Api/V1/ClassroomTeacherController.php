<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\CoTeachers;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\ClassroomTeacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The owner's side of co-teaching (DESIGN §29.13.4): the invitations and
 * co-teachers of a classroom. A subject teacher gets 403
 * not_homeroom_teacher; anybody else a 404.
 */
class ClassroomTeacherController extends Controller
{
    public function __construct(private readonly CoTeachers $coTeachers) {}

    /** GET /api/v1/classrooms/{id}/teachers -> {data: [{id, email, status, teacher, courses, invited_at, decided_at}]} */
    public function index(Request $request, int $id): JsonResponse
    {
        $classroom = $this->owned($request, $id);

        return response()->json(['data' => $this->coTeachers->listFor($classroom)]);
    }

    /** POST /api/v1/classrooms/{id}/teachers {email} -> 201 {data: row}; 409 invitation_exists */
    public function store(Request $request, int $id): JsonResponse
    {
        $classroom = $this->owned($request, $id);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'email.required' => 'กรอกอีเมลของครูที่จะเชิญ',
            'email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
        ]);

        $row = $this->coTeachers->invite($request->user(), $classroom, $data['email']);

        return response()->json(['data' => CoTeachers::payload($row->load('teacher:id,name'), $classroom)], 201);
    }

    /** DELETE /api/v1/classrooms/{id}/teachers/{row_id} -> 204; 409 course_in_use */
    public function destroy(Request $request, int $id, int $rowId): Response
    {
        $classroom = $this->owned($request, $id);
        $row = ClassroomTeacher::query()->where('classroom_id', $classroom->id)->findOrFail($rowId);

        $this->coTeachers->remove($classroom, $row);

        return response()->noContent();
    }

    /** The classroom when the caller owns it (404 unseen, 403 not_homeroom_teacher for a subject teacher). */
    private function owned(Request $request, int $id): Classroom
    {
        $classroom = ClassroomAccess::classrooms($request->user())->findOrFail($id);
        Gate::authorize('update', $classroom);

        return $classroom;
    }
}
