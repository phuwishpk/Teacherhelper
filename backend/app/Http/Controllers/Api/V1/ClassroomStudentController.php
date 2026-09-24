<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\StudentEnroller;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkStoreStudentsRequest;
use App\Http\Resources\RosterStudentResource;
use App\Models\Classroom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Roster of a classroom (DESIGN §9.2): bulk add and list.
 */
class ClassroomStudentController extends Controller
{
    public function __construct(private readonly StudentEnroller $enroller) {}

    /**
     * POST /api/v1/classrooms/{id}/students {students: [{name, student_number}]}
     * -> 201 {data: [{student_id, student_number, name, status, pin}]}
     *
     * The PIN is returned exactly once (only its hash is stored); the teacher
     * reads it out to the student. The QR card comes from /login-cards.
     * A student_number already in the classroom -> 422 student_number_taken
     * (StudentEnroller, inside the transaction).
     */
    public function store(BulkStoreStudentsRequest $request, int $id): JsonResponse
    {
        $classroom = $this->ownClassroom($request, $id);
        Gate::authorize('manageStudents', $classroom);

        $created = $this->enroller->enroll($classroom, $request->validated('students'));

        return response()->json([
            'data' => array_map(fn (array $row) => [
                'student_id' => $row['student']->id,
                'student_number' => $row['student_number'],
                'name' => $row['student']->name,
                'status' => $row['student']->status,
                'pin' => $row['pin'],
            ], $created),
        ], 201);
    }

    /**
     * GET /api/v1/classrooms/{id}/roster -> {data: [{student_id, student_number, name}]}
     * Not paginated: the app caches the whole roster for offline scanning.
     */
    public function index(Request $request, int $id): AnonymousResourceCollection
    {
        $classroom = $this->ownClassroom($request, $id);
        Gate::authorize('manageStudents', $classroom);

        return RosterStudentResource::collection($classroom->students()->get());
    }

    private function ownClassroom(Request $request, int $id): Classroom
    {
        $teacher = $request->user();

        return Classroom::query()
            ->where('school_id', $teacher->school_id)
            ->where('teacher_id', $teacher->id)
            ->findOrFail($id);
    }
}
