<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\CourseRequests;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Shared homerooms (DESIGN §24.7, §24.12 B): the school's directory of open
 * classrooms to ask for, the courses taught in a classroom, a request to
 * bind an own course to a classroom, and unbinding.
 */
class ClassroomCourseController extends Controller
{
    /** At most this many classrooms in one directory answer. */
    public const DIRECTORY_LIMIT = 100;

    public function __construct(private readonly CourseRequests $requests) {}

    /**
     * GET /api/v1/classrooms/{id}/courses -> {data: [{course: {id, code, name,
     * subject: {id, code, name}, grade_level, semester, academic_year},
     * teacher: {id, name}, is_mine}]}: every course taught in the classroom
     * for its homeroom teacher, the teacher's own ones for a subject teacher
     * (DESIGN §24.8: a subject teacher sees only their own course).
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $teacher = $request->user();
        $classroom = ClassroomAccess::classrooms($teacher)->findOrFail($id);
        Gate::authorize('view', $classroom);
        $homeroom = ClassroomAccess::homeroomOf($teacher, $classroom);

        $courses = $classroom->courses()
            ->when(! $homeroom, fn ($q) => $q->where('courses.created_by', $teacher->id))
            ->with(['subject', 'creator:id,name'])
            ->orderBy('courses.code')
            ->orderBy('courses.id')
            ->get();

        return response()->json(['data' => $courses->map(fn (Course $c) => [
            'course' => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'subject' => $c->subject === null ? null : ['id' => $c->subject->id, 'code' => $c->subject->code, 'name' => $c->subject->name],
                'grade_level' => $c->grade_level,
                'semester' => $c->semester,
                'academic_year' => $c->academic_year,
            ],
            'teacher' => $c->creator === null ? null : ['id' => $c->creator->id, 'name' => $c->creator->name],
            'is_mine' => $c->created_by === $teacher->id,
        ])->values()->all()]);
    }

    /**
     * DELETE /api/v1/classrooms/{id}/courses/{course_id} -> 204: unbinds a
     * course (DESIGN §24.7 step 5) by the homeroom teacher or the course's
     * creator; 409 course_in_use while the classroom has assignments of it.
     * A course not bound to the classroom, or another teacher's course for a
     * subject teacher, is a 404.
     */
    public function destroy(Request $request, int $id, int $courseId): Response
    {
        $teacher = $request->user();
        $classroom = ClassroomAccess::classrooms($teacher)->findOrFail($id);
        Gate::authorize('unbindCourse', $classroom);
        $course = $classroom->courses()
            ->when(! ClassroomAccess::homeroomOf($teacher, $classroom), fn ($q) => $q->where('courses.created_by', $teacher->id))
            ->findOrFail($courseId);

        $this->requests->unbind($classroom, $course);

        return response()->noContent();
    }
}
