<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClosedClassrooms;
use App\Domain\Courses\CourseEditor;
use App\Domain\Courses\CourseInputs;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The teacher's courses (รายวิชา, DESIGN §20.1, §20.7). Queries are scoped
 * to courses the teacher created in their school, so anything else is a
 * 404, and CoursePolicy runs on top.
 */
class CourseController extends Controller
{
    public function __construct(private readonly CourseEditor $editor) {}

    /** GET /api/v1/courses?classroom_id= -> {data: [course]} newest academic year first */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Course::class);
        $validated = $request->validate(['classroom_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        $query = self::ownQuery($request)
            ->with(['subject', 'classrooms'])
            ->withCount(['indicators', 'units', 'lessonPlans', 'assignments'])
            ->orderByDesc('academic_year')
            ->orderByDesc('semester')
            ->orderBy('code')
            ->orderBy('id');
        if (isset($validated['classroom_id'])) {
            $query->whereHas('classrooms', fn (Builder $q) => $q->whereKey((int) $validated['classroom_id']));
        }

        return response()->json(['data' => $query->get()->map(fn (Course $c) => CourseResource::summary($c))->all()]);
    }

    /**
     * POST /api/v1/courses {code, name, subject_id, grade_level, semester?,
     * academic_year, hours?, description?, classroom_ids?[], skill_ids?[]}
     * -> 201 {data: course detail}
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Course::class);
        $teacher = $request->user();
        $data = CourseInputs::validate($request->all(), CourseInputs::courseRules(false));
        $classroomIds = $request->has('classroom_ids') ? CourseInputs::classroomIds($teacher, $request->input('classroom_ids')) : [];
        ClosedClassrooms::assertAllOpen($classroomIds); // §24.6
        $skillIds = $request->has('skill_ids') ? CourseInputs::skillIds($teacher, $request->input('skill_ids')) : [];

        $course = $this->editor->create($teacher, $data, $classroomIds, $skillIds);

        return response()->json(['data' => CourseResource::detail(CourseResource::loadDetail($course))], 201);
    }

    /** GET /api/v1/courses/{id} -> {data: course detail with indicators, units and lesson plans} */
    public function show(Request $request, int $id): JsonResponse
    {
        $course = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $course);

        return self::detail($course);
    }

    /** PATCH /api/v1/courses/{id} {code?, name?, subject_id?, grade_level?, semester?, academic_year?, hours?, description?} */
    public function update(Request $request, int $id): JsonResponse
    {
        $course = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $course);
        $data = CourseInputs::validate($request->all(), CourseInputs::courseRules(true));

        return self::detail($this->editor->update($course, $data));
    }

    /** DELETE /api/v1/courses/{id} -> 204; 409 course_in_use while assignments use it */
    public function destroy(Request $request, int $id): Response
    {
        $course = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('delete', $course);
        $this->editor->delete($course);

        return response()->noContent();
    }

    /**
     * PUT /api/v1/courses/{id}/classrooms {classroom_ids[]}: the teacher's
     * own classrooms only; a classroom whose assignments use the course
     * stays (409 course_in_use).
     */
    public function classrooms(Request $request, int $id): JsonResponse
    {
        $course = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $course);
        $ids = CourseInputs::classroomIds($request->user(), $request->input('classroom_ids'));
        // Binding or unbinding a closed classroom is a write on it (§24.6).
        $current = $course->classrooms()->pluck('classrooms.id')->map(fn ($id) => (int) $id)->all();
        ClosedClassrooms::assertAllOpen([...array_diff($ids, $current), ...array_diff($current, $ids)]);
        $this->editor->setClassrooms($course, $ids);

        return self::detail($course);
    }

    /** PUT /api/v1/courses/{id}/indicators {skill_ids[]}: indicators and sub-indicators the school sees */
    public function indicators(Request $request, int $id): JsonResponse
    {
        $course = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $course);
        $ids = CourseInputs::skillIds($request->user(), $request->input('skill_ids'));
        $this->editor->setCourseIndicators($course, $ids);

        return self::detail($course);
    }

    /**
     * Courses the teacher created in their school.
     *
     * @return Builder<Course>
     */
    public static function ownQuery(Request $request): Builder
    {
        $teacher = $request->user();

        return Course::query()
            ->where('school_id', $teacher->school_id)
            ->where('created_by', $teacher->id);
    }

    private static function detail(Course $course): JsonResponse
    {
        return response()->json(['data' => CourseResource::detail(CourseResource::loadDetail($course->refresh()))]);
    }
}
