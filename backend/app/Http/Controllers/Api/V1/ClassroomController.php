<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Domain\Classrooms\ClassroomLifecycle;
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
use Symfony\Component\HttpFoundation\Response;

/**
 * Classrooms of the signed-in teacher (DESIGN §9.2). Every query is scoped to
 * the teacher and their school, so another teacher's classroom is a 404, and
 * the policy is checked on top of that.
 */
class ClassroomController extends Controller
{
    public const PER_PAGE = 50;

    public function __construct(private readonly ClassroomLifecycle $lifecycle) {}

    /**
     * GET /api/v1/classrooms?state=open|closed -> cursor-paginated {data: [...],
     * meta: {next_cursor}}: the open classrooms by default, the closed ones
     * ("ห้องเก่า", DESIGN §24.6) with state=closed.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Classroom::class);
        $state = $request->validate([
            'state' => ['sometimes', 'string', 'in:open,closed'],
        ], ['state.in' => 'state ต้องเป็น open หรือ closed'])['state'] ?? 'open';

        $classrooms = $this->ownQuery($request)
            ->when($state === 'open', fn (Builder $q) => $q->whereNull('closed_at'), fn (Builder $q) => $q->whereNotNull('closed_at'))
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

    /** POST /api/v1/classrooms/{id}/close -> {data: classroom}: moves it to "ห้องเก่า" (DESIGN §24.6) */
    public function close(Request $request, int $id): ClassroomResource
    {
        $classroom = $this->ownQuery($request)->findOrFail($id);
        Gate::authorize('close', $classroom);

        $this->lifecycle->close($classroom, $request->user());

        return new ClassroomResource($classroom->loadCount('students')->load('googleLink'));
    }

    /** POST /api/v1/classrooms/{id}/reopen -> {data: classroom}: undoes a close made by mistake */
    public function reopen(Request $request, int $id): ClassroomResource
    {
        $classroom = $this->ownQuery($request)->findOrFail($id);
        Gate::authorize('reopen', $classroom);

        $this->lifecycle->reopen($classroom);

        return new ClassroomResource($classroom->loadCount('students')->load('googleLink'));
    }

    /**
     * DELETE /api/v1/classrooms/{id} -> 204, only while the classroom has no
     * submission, gradebook entry or publication (409 classroom_has_data
     * with counts). Student accounts stay.
     */
    public function destroy(Request $request, int $id): Response
    {
        $classroom = $this->ownQuery($request)->findOrFail($id);
        Gate::authorize('delete', $classroom);

        $this->lifecycle->delete($classroom);

        return response()->noContent();
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
