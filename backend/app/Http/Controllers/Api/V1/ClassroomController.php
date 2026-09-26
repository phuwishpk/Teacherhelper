<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClassroomRequest;
use App\Http\Requests\Api\V1\UpdateClassroomRequest;
use App\Http\Resources\ClassroomResource;
use App\Models\Classroom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Classrooms of the signed-in teacher (DESIGN §9.2). Every query is scoped to
 * the teacher and their school, so another teacher's classroom is a 404, and
 * the policy is checked on top of that.
 */
class ClassroomController extends Controller
{
    public const PER_PAGE = 50;

    /** GET /api/v1/classrooms -> cursor-paginated {data: [...], meta: {next_cursor}} */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Classroom::class);

        $classrooms = $this->ownQuery($request)
            ->withCount('students')
            ->with('googleLink')
            ->orderByDesc('academic_year')
            ->orderBy('grade_level')
            ->orderBy('name')
            ->orderBy('id')
            ->cursorPaginate(self::PER_PAGE);

        return ClassroomResource::collection($classrooms);
    }

    /** POST /api/v1/classrooms -> 201 {data: classroom} with a fresh class_code */
    public function store(StoreClassroomRequest $request): JsonResponse
    {
        Gate::authorize('create', Classroom::class);
        $teacher = $request->user();

        $classroom = Classroom::create([
            'school_id' => $teacher->school_id,
            'teacher_id' => $teacher->id,
            ...$request->validated(),
            'class_code' => ClassCodeGenerator::unique(),
        ]);
        $classroom->loadCount('students')->load('googleLink');

        return (new ClassroomResource($classroom))->response()->setStatusCode(201);
    }

    /** GET /api/v1/classrooms/{id} */
    public function show(Request $request, int $id): ClassroomResource
    {
        $classroom = $this->ownQuery($request)->withCount('students')->with('googleLink')->findOrFail($id);
        Gate::authorize('view', $classroom);

        return new ClassroomResource($classroom);
    }

    /** PATCH /api/v1/classrooms/{id} */
    public function update(UpdateClassroomRequest $request, int $id): ClassroomResource
    {
        $classroom = $this->ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $classroom);

        $classroom->fill($request->validated())->save();
        $classroom->loadCount('students')->load('googleLink');

        return new ClassroomResource($classroom);
    }

    /** @return Builder<Classroom> */
    private function ownQuery(Request $request)
    {
        $teacher = $request->user();

        return Classroom::query()
            ->where('school_id', $teacher->school_id)
            ->where('teacher_id', $teacher->id);
    }
}
