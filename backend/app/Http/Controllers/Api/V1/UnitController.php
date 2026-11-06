<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Courses\CourseEditor;
use App\Domain\Courses\CourseInputs;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Units of a course (หน่วยการเรียนรู้, DESIGN §20.7), reached through the
 * teacher's own courses only (404 otherwise).
 */
class UnitController extends Controller
{
    public function __construct(private readonly CourseEditor $editor) {}

    /** POST /api/v1/courses/{id}/units {title, hours?, description?, position?, skill_ids?[]} -> 201 {data: unit} */
    public function store(Request $request, int $id): JsonResponse
    {
        $course = CourseController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $course);
        $data = CourseInputs::validate($request->all(), CourseInputs::unitRules(false));
        $skillIds = $request->has('skill_ids') ? CourseInputs::skillIds($request->user(), $request->input('skill_ids')) : [];

        $unit = $this->editor->addUnit($course, $data, $skillIds);

        return response()->json(['data' => CourseResource::unit($unit->load('indicators'))], 201);
    }

    /** PATCH /api/v1/units/{id} {title?, hours?, description?, position?, skill_ids?[]} -> {data: unit} */
    public function update(Request $request, int $id): JsonResponse
    {
        $unit = self::find($request, $id);
        $data = CourseInputs::validate($request->all(), CourseInputs::unitRules(true));
        $skillIds = $request->has('skill_ids') ? CourseInputs::skillIds($request->user(), $request->input('skill_ids')) : null;

        return response()->json(['data' => CourseResource::unit($this->editor->updateUnit($unit, $data, $skillIds)->load('indicators'))]);
    }

    /** DELETE /api/v1/units/{id} -> 204; its lesson plans stay in the course without a unit */
    public function destroy(Request $request, int $id): Response
    {
        $this->editor->deleteUnit(self::find($request, $id));

        return response()->noContent();
    }

    /** PUT /api/v1/units/{id}/indicators {skill_ids[]} -> {data: unit} */
    public function indicators(Request $request, int $id): JsonResponse
    {
        $unit = self::find($request, $id);
        $skillIds = CourseInputs::skillIds($request->user(), $request->input('skill_ids'));

        return response()->json(['data' => CourseResource::unit($this->editor->updateUnit($unit, [], $skillIds)->load('indicators'))]);
    }

    private static function find(Request $request, int $id): Unit
    {
        $unit = Unit::query()
            ->whereIn('course_id', CourseController::ownQuery($request)->select('id'))
            ->with('course')
            ->findOrFail($id);
        Gate::authorize('update', $unit->course);

        return $unit;
    }
}
