<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Courses\CourseEditor;
use App\Domain\Courses\CourseInputs;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\LessonPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Lesson plans of a course (แผนการจัดการเรียนรู้, DESIGN §20.7), reached
 * through the teacher's own courses only (404 otherwise). PATCH {taught_on}
 * marks a plan taught (chart 5, §20.4).
 */
class LessonPlanController extends Controller
{
    public function __construct(private readonly CourseEditor $editor) {}

    /**
     * POST /api/v1/courses/{id}/lesson-plans {title, unit_id?, hours?,
     * objectives?, content?, activities?, assessment?, taught_on?,
     * position?, skill_ids?[]} -> 201 {data: plan}
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $course = CourseController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $course);
        $data = CourseInputs::validate($request->all(), CourseInputs::planRules(false));
        $skillIds = $request->has('skill_ids') ? CourseInputs::skillIds($request->user(), $request->input('skill_ids')) : [];

        $plan = $this->editor->addPlan($course, $data, $skillIds);

        return response()->json(['data' => CourseResource::plan($plan->load('indicators'))], 201);
    }

    /** GET /api/v1/lesson-plans/{id} -> {data: plan} */
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => CourseResource::plan(self::find($request, $id)->load('indicators'))]);
    }

    /** PATCH /api/v1/lesson-plans/{id} (the fields of POST, all optional) -> {data: plan} */
    public function update(Request $request, int $id): JsonResponse
    {
        $plan = self::find($request, $id);
        $data = CourseInputs::validate($request->all(), CourseInputs::planRules(true));
        $skillIds = $request->has('skill_ids') ? CourseInputs::skillIds($request->user(), $request->input('skill_ids')) : null;

        return response()->json(['data' => CourseResource::plan($this->editor->updatePlan($plan, $data, $skillIds)->load('indicators'))]);
    }

    /** DELETE /api/v1/lesson-plans/{id} -> 204; assignments linked to it keep their course */
    public function destroy(Request $request, int $id): Response
    {
        $this->editor->deletePlan(self::find($request, $id));

        return response()->noContent();
    }

    /** PUT /api/v1/lesson-plans/{id}/indicators {skill_ids[]} -> {data: plan} */
    public function indicators(Request $request, int $id): JsonResponse
    {
        $plan = self::find($request, $id);
        $skillIds = CourseInputs::skillIds($request->user(), $request->input('skill_ids'));

        return response()->json(['data' => CourseResource::plan($this->editor->updatePlan($plan, [], $skillIds)->load('indicators'))]);
    }

    private static function find(Request $request, int $id): LessonPlan
    {
        $plan = LessonPlan::query()
            ->whereIn('course_id', CourseController::ownQuery($request)->select('id'))
            ->with('course')
            ->findOrFail($id);
        Gate::authorize('update', $plan->course);

        return $plan;
    }
}
