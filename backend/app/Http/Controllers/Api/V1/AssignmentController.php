<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssignmentIndexRequest;
use App\Http\Requests\Api\V1\StoreAssignmentRequest;
use App\Http\Requests\Api\V1\UpdateAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Classroom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Assignments of the signed-in teacher's classrooms (DESIGN §9.3). Queries are
 * scoped to classrooms the teacher teaches in their school, so anything else
 * is a 404, and the policy runs on top.
 */
class AssignmentController extends Controller
{
    public const PER_PAGE = 50;

    /** GET /api/v1/assignments?classroom_id=&status= -> cursor-paginated */
    public function index(AssignmentIndexRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Assignment::class);

        $query = self::ownQuery($request)
            ->with(['classroom', 'subject', 'googleLink'])
            // submissions_count: students who handed in anything (§19.6 "อัปโหลดรูปเพื่อตรวจ").
            ->withCount(['questions', 'submissions'])
            ->orderByDesc('id');

        if ($request->filled('classroom_id')) {
            $query->where('classroom_id', (int) $request->validated('classroom_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        return AssignmentResource::collection($query->cursorPaginate(self::PER_PAGE));
    }

    /** POST /api/v1/assignments -> 201 {data: assignment} */
    public function store(StoreAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('create', Assignment::class);
        $teacher = $request->user();
        $classroom = Classroom::query()->findOrFail($request->validated('classroom_id'));

        $assignment = Assignment::create([
            'school_id' => $classroom->school_id,
            'classroom_id' => $classroom->id,
            'subject_id' => $request->validated('subject_id'),
            'created_by' => $teacher->id,
            'title' => trim($request->validated('title')),
            'strictness' => $request->validated('strictness') ?? 'normal',
            'status' => Assignment::STATUS_DRAFT,
            'due_at' => self::utc($request->validated('due_at')),
            'mode' => $request->validated('mode') ?? Assignment::MODE_WORKSHEET,
            'accept_late' => (bool) ($request->validated('accept_late') ?? true),
            'score_only' => (bool) ($request->validated('score_only') ?? false),
        ]);

        return (new AssignmentResource(self::loadDetail($assignment)))->response()->setStatusCode(201);
    }

    /** GET /api/v1/assignments/{id} -> {data: assignment with questions, skills and rubric criteria} */
    public function show(Request $request, int $id): AssignmentResource
    {
        $assignment = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $assignment);

        return new AssignmentResource(self::loadDetail($assignment));
    }

    /**
     * PATCH /api/v1/assignments/{id} {title?, strictness?, due_at?, status?: draft|closed,
     * mode?, accept_late?, score_only?}. mode changes only on a draft that
     * never had a layout or a submission (422 errors.mode). A freeform
     * assignment sent back to draft loses its key approval (ready ⇔
     * approved, DESIGN §19.5).
     */
    public function update(UpdateAssignmentRequest $request, int $id): AssignmentResource
    {
        $assignment = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $assignment);

        $data = $request->validated();
        DB::transaction(function () use ($assignment, $data) {
            $assignment = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if (array_key_exists('title', $data)) {
                $assignment->title = trim($data['title']);
            }
            if (array_key_exists('strictness', $data)) {
                $assignment->strictness = $data['strictness'];
            }
            if (array_key_exists('due_at', $data)) {
                $assignment->due_at = self::utc($data['due_at']);
            }
            if (array_key_exists('mode', $data) && $data['mode'] !== $assignment->mode) {
                if (! $assignment->isDraft() || $assignment->current_layout_version !== null
                    || $assignment->layouts()->exists() || $assignment->submissions()->exists()) {
                    throw ValidationException::withMessages([
                        'mode' => 'เปลี่ยนโหมดได้เฉพาะการบ้านฉบับร่างที่ยังไม่เคยสร้างใบงานและยังไม่มีงานส่ง',
                    ]);
                }
                $assignment->mode = $data['mode'];
            }
            foreach (['accept_late', 'score_only'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $assignment->{$flag} = (bool) $data[$flag];
                }
            }
            if (array_key_exists('status', $data)) {
                // closed: stop edits and printing; draft: reopen (rebuild the layout to print again).
                $assignment->status = $data['status'];
                if ($data['status'] === Assignment::STATUS_DRAFT && $assignment->isFreeform()) {
                    $assignment->key_approved_at = null;
                    $assignment->key_approved_by = null;
                }
            }
            $assignment->save();
        });

        return new AssignmentResource(self::loadDetail($assignment->refresh()));
    }

    /** DELETE /api/v1/assignments/{id} -> 204; drafts that were never printed only */
    public function destroy(Request $request, int $id): Response
    {
        $assignment = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('delete', $assignment);

        DB::transaction(function () use ($assignment) {
            $assignment = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if (! $assignment->isDraft()) {
                throw new ApiException('ลบได้เฉพาะการบ้านที่ยังเป็นฉบับร่าง', 'assignment_not_draft', 409);
            }
            if ($assignment->worksheetPrints()->exists()) {
                throw new ApiException('การบ้านนี้พิมพ์ใบงานไปแล้ว ลบไม่ได้ ให้ปิดการบ้านแทน', 'assignment_printed', 409);
            }
            if ($assignment->googleLink()->exists()) {
                // The courseWork lives on in Classroom and its submissions refer to this row (§18.4).
                throw new ApiException('การบ้านนี้โพสต์ลง Google Classroom แล้ว ลบไม่ได้ ให้ปิดการบ้านแทน', 'assignment_posted', 409);
            }
            $assignment->delete();
        });

        return response()->noContent();
    }

    /**
     * Assignments of classrooms the teacher teaches, in the teacher's school.
     *
     * @return Builder<Assignment>
     */
    public static function ownQuery(Request $request): Builder
    {
        $teacher = $request->user();

        return Assignment::query()
            ->where('school_id', $teacher->school_id)
            ->whereIn('classroom_id', Classroom::query()->select('id')->where('teacher_id', $teacher->id));
    }

    public static function loadDetail(Assignment $assignment): Assignment
    {
        return $assignment->load(['classroom', 'subject', 'googleLink', 'questions.skills', 'questions.rubricCriteria'])
            ->loadCount(['questions', 'responses as missing_ai_key_count' => fn ($q) => $q->awaitingAiKey()]);
    }

    private static function utc(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
