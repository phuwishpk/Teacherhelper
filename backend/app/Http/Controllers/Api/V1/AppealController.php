<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Review\Appeals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AppealIndexRequest;
use App\Http\Requests\Api\V1\ResolveAppealRequest;
use App\Http\Resources\AppealResource;
use App\Models\Appeal;
use App\Models\ClassroomStudent;
use App\Models\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The teacher's side of appeals (DESIGN §9.5, §13). Only appeals on answers
 * of the teacher's own classrooms exist for them (others are 404).
 */
class AppealController extends Controller
{
    public const PER_PAGE = 50;

    private const RELATIONS = [
        'response.question',
        'response.submission.assignment.classroom',
        'response.submission.student:id,name',
    ];

    public function __construct(private readonly Appeals $appeals) {}

    /**
     * GET /api/v1/appeals?status=open|accepted|rejected&assignment_id=&cursor=
     * -> {data: [AppealResource], meta: {next_cursor, prev_cursor, per_page}},
     * newest first.
     */
    public function index(AppealIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Appeal::class);

        $query = Appeals::forTeacher($request->user())->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('assignment_id')) {
            $assignmentId = (int) $request->validated('assignment_id');
            $query->whereHas('response.submission', fn ($q) => $q->where('assignment_id', $assignmentId));
        }

        $page = $query->with(self::RELATIONS)->cursorPaginate(self::PER_PAGE);
        $numbers = self::studentNumbers(collect($page->items()));

        return response()->json([
            'data' => array_map(
                fn (Appeal $appeal) => (new AppealResource($appeal, self::numberOf($appeal, $numbers)))->resolve($request),
                $page->items(),
            ),
            'meta' => [
                'per_page' => self::PER_PAGE,
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/appeals/{id} {status: accepted|rejected, teacher_note?,
     * final_score?, final_understanding?} -> {data: AppealResource};
     * 409 appeal_resolved when it was answered already.
     */
    public function update(ResolveAppealRequest $request, int $id): JsonResponse
    {
        $appeal = Appeals::forTeacher($request->user())->with(self::RELATIONS)->findOrFail($id);
        Gate::authorize('resolve', $appeal);

        $appeal = $this->appeals->resolve($request->user(), $appeal, $request->validated());
        $appeal->load(self::RELATIONS);
        $numbers = self::studentNumbers(collect([$appeal]));

        return response()->json(['data' => (new AppealResource($appeal, self::numberOf($appeal, $numbers)))->resolve($request)]);
    }

    /**
     * @param  Collection<int, Appeal>  $appeals
     * @return array<string, int> "classroom_id:student_id" => number
     */
    private static function studentNumbers(Collection $appeals): array
    {
        $classrooms = $appeals->map(fn (Appeal $a) => $a->response?->submission?->assignment?->classroom_id)->filter()->unique()->values();
        if ($classrooms->isEmpty()) {
            return [];
        }

        return ClassroomStudent::query()
            ->whereIn('classroom_id', $classrooms)
            ->whereIn('student_id', $appeals->pluck('student_id')->unique()->values())
            ->get(['classroom_id', 'student_id', 'student_number'])
            ->mapWithKeys(fn (ClassroomStudent $row) => [$row->classroom_id.':'.$row->student_id => (int) $row->student_number])
            ->all();
    }

    /**
     * @param  array<string, int>  $numbers
     */
    private static function numberOf(Appeal $appeal, array $numbers): ?int
    {
        $response = $appeal->response;
        $classroomId = $response instanceof Response ? $response->submission?->assignment?->classroom_id : null;

        return $numbers[$classroomId.':'.$appeal->student_id] ?? null;
    }
}
