<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\CourseRequests;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseRequestResource;
use App\Models\Classroom;
use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;
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
     * GET /api/v1/classrooms/directory?q=&academic_year= -> {data: [{id, name,
     * grade_level, academic_year, homeroom_teacher: {id, name},
     * students_count, my_role: homeroom|subject|null}]}: the open classrooms
     * of the school, no roster (DESIGN §24.7 step 1). q matches the
     * classroom's name or its homeroom teacher's name.
     */
    public function directory(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Classroom::class);
        $teacher = $request->user();
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'academic_year' => ['sometimes', 'nullable', 'integer', 'min:2500', 'max:2700'],
        ], [
            'q.max' => 'คำค้นยาวเกินไป',
            'academic_year.*' => 'ปีการศึกษาต้องเป็น พ.ศ.',
        ]);
        $q = trim((string) ($data['q'] ?? ''));
        $subjectIds = ClassroomAccess::subjectClassroomIds($teacher)->pluck('classroom_id')->map(fn ($id) => (int) $id)->all();

        $rooms = Classroom::query()
            ->where('school_id', $teacher->school_id)
            ->whereNull('closed_at')
            ->when(isset($data['academic_year']), fn (Builder $b) => $b->where('academic_year', (int) $data['academic_year']))
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.self::escapeLike($q).'%')
                ->orWhereHas('teacher', fn (Builder $t) => $t->where('name', 'like', '%'.self::escapeLike($q).'%'))))
            ->with('teacher:id,name')
            ->withCount('students')
            ->orderByDesc('academic_year')
            ->orderBy('grade_level')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::DIRECTORY_LIMIT)
            ->get();

        return response()->json(['data' => $rooms->map(fn (Classroom $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'grade_level' => $c->grade_level,
            'academic_year' => $c->academic_year,
            'homeroom_teacher' => $c->teacher === null ? null : ['id' => $c->teacher->id, 'name' => $c->teacher->name],
            'students_count' => (int) $c->students_count,
            'my_role' => $c->teacher_id === $teacher->id ? ClassroomAccess::HOMEROOM : (in_array($c->id, $subjectIds, true) ? ClassroomAccess::SUBJECT : null),
        ])->values()->all()]);
    }

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
     * POST /api/v1/classrooms/{id}/course-requests {course_id, message?} ->
     * 201 {data: request}, or 200 {data: {bound: true, classroom_id,
     * course_id}} when the caller is the classroom's homeroom teacher (bound
     * at once). The course must be the caller's (404), the classroom of the
     * same school (404) and open (409 classroom_closed); 409
     * course_already_in_classroom, request_pending (DESIGN §24.7).
     */
    public function storeRequest(Request $request, int $id): JsonResponse
    {
        Gate::authorize('viewAny', Classroom::class);
        $teacher = $request->user();
        $classroom = Classroom::query()->where('school_id', $teacher->school_id)->findOrFail($id);
        $data = $request->validate([
            'course_id' => ['required', 'integer', 'min:1'],
            'message' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [
            'course_id.required' => 'กรุณาเลือกรายวิชา',
            'course_id.integer' => 'รหัสรายวิชาไม่ถูกต้อง',
            'message.max' => 'ข้อความยาวไม่เกิน 255 ตัวอักษร',
        ]);
        $course = CourseController::ownQuery($request)->findOrFail((int) $data['course_id']);
        Gate::authorize('update', $course);

        $row = $this->requests->request($teacher, $classroom, $course, $data['message'] ?? null);
        if ($row === null) {
            return response()->json(['data' => ['bound' => true, 'classroom_id' => $classroom->id, 'course_id' => $course->id]]);
        }

        return response()->json(['data' => CourseRequestResource::payload($row)], 201);
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

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
