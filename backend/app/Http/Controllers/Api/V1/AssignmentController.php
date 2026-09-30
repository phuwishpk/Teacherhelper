<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Courses\AssignmentCourses;
use App\Domain\Exams\ExamImages;
use App\Domain\Exams\ExamSettings;
use App\Domain\Gradebook\AssignmentCategories;
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

    /** GET /api/v1/assignments?classroom_id=&course_id=&lesson_plan_id=&status=&kind= -> cursor-paginated */
    public function index(AssignmentIndexRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Assignment::class);

        $query = self::ownQuery($request)
            ->with(['classroom', 'subject', 'googleLink', 'course', 'lessonPlan'])
            // submissions_count: students who handed in anything (§19.6 "อัปโหลดรูปเพื่อตรวจ").
            ->withCount(['questions', 'submissions'])
            ->orderByDesc('id');

        if ($request->filled('classroom_id')) {
            $query->where('classroom_id', (int) $request->validated('classroom_id'));
        }
        foreach (['course_id', 'lesson_plan_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (int) $request->validated($filter));
            }
        }
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('kind')) {
            $query->where('kind', $request->validated('kind'));
        }

        return AssignmentResource::collection($query->cursorPaginate(self::PER_PAGE));
    }

    /** POST /api/v1/assignments -> 201 {data: assignment}; kind = exam creates an exam (DESIGN §22.1) */
    public function store(StoreAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('create', Assignment::class);
        $teacher = $request->user();
        $classroom = Classroom::query()->findOrFail($request->validated('classroom_id'));
        // Every new assignment belongs to a course of its classroom (§20.1).
        $course = AssignmentCourses::courseFor($teacher, $classroom->id, $request->validated('course_id'));
        $plan = AssignmentCourses::planFor($course, $request->validated('lesson_plan_id'));

        $exam = $request->validated('kind') === Assignment::KIND_EXAM;
        $grading = $exam ? ($request->validated('grading_method') ?? Assignment::GRADING_APP) : null;
        if ($exam && ($request->validated('mode') ?? Assignment::MODE_WORKSHEET) !== Assignment::MODE_WORKSHEET) {
            throw ValidationException::withMessages(['mode' => 'ข้อสอบไม่ใช้โหมด freeform']);
        }

        $categoryId = AssignmentCategories::forNew($course->id, $exam, $request->has('gradebook_category_id'), $request->validated('gradebook_category_id'));

        $assignment = Assignment::create([
            'school_id' => $classroom->school_id,
            'classroom_id' => $classroom->id,
            'subject_id' => $course->subject_id,
            'course_id' => $course->id,
            'lesson_plan_id' => $plan?->id,
            'created_by' => $teacher->id,
            'title' => trim($request->validated('title')),
            'strictness' => $request->validated('strictness') ?? 'normal',
            'status' => Assignment::STATUS_DRAFT,
            'due_at' => self::utc($request->validated('due_at')),
            'mode' => $request->validated('mode') ?? Assignment::MODE_WORKSHEET,
            'accept_late' => (bool) ($request->validated('accept_late') ?? true),
            'score_only' => (bool) ($request->validated('score_only') ?? false),
            'kind' => $exam ? Assignment::KIND_EXAM : Assignment::KIND_HOMEWORK,
            'grading_method' => $grading,
            'version_count' => $exam ? (int) ($request->validated('version_count') ?? 1) : 1,
            'duration_minutes' => $exam ? $request->validated('duration_minutes') : null,
            'show_key_to_students' => $exam && (bool) ($request->validated('show_key_to_students') ?? false),
            'manual_full_marks' => $exam ? $request->validated('manual_full_marks') : null,
            'gradebook_category_id' => $categoryId,
            'excluded_from_grade' => (bool) ($request->validated('excluded_from_grade') ?? false),
        ]);
        if ($grading === Assignment::GRADING_MANUAL) {
            // No key gate for an exam graded by hand: ready from the start (DESIGN §22.1).
            $assignment->status = Assignment::STATUS_READY;
            $assignment->save();
        }

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
     * mode?, accept_late?, score_only?, course_id?, lesson_plan_id?,
     * gradebook_category_id?, excluded_from_grade?}. course_id:
     * a course bound to the classroom (the subject follows it); lesson_plan_id:
     * a plan of the assignment's course, or null. mode changes only on a draft that
     * never had a layout or a submission (422 errors.mode). A freeform
     * assignment sent back to draft loses its key approval (ready ⇔
     * approved, DESIGN §19.5). Exam fields: see ExamSettings (DESIGN §22.1,
     * §22.5); an exam keeps its due_at and its worksheet mode, a manual exam
     * reopened with status draft is ready again (no key gate).
     */
    public function update(UpdateAssignmentRequest $request, int $id): AssignmentResource
    {
        $assignment = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $assignment);

        $data = $request->validated();
        $teacher = $request->user();
        DB::transaction(function () use ($assignment, $data, $teacher) {
            $assignment = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if (array_key_exists('course_id', $data)) {
                AssignmentCourses::assign($assignment, AssignmentCourses::courseFor($teacher, $assignment->classroom_id, $data['course_id']));
            }
            if (array_key_exists('lesson_plan_id', $data)) {
                if ($data['lesson_plan_id'] !== null && $assignment->course_id === null) {
                    throw ValidationException::withMessages(['lesson_plan_id' => 'เลือกรายวิชาของการบ้านก่อนเลือกแผนการสอน']);
                }
                $assignment->lesson_plan_id = $data['lesson_plan_id'] === null
                    ? null
                    : AssignmentCourses::planFor($assignment->course()->firstOrFail(), $data['lesson_plan_id'])?->id;
            }
            if (array_key_exists('gradebook_category_id', $data)) {
                AssignmentCategories::set($assignment, $data['gradebook_category_id']);
            }
            if (array_key_exists('excluded_from_grade', $data)) {
                $assignment->excluded_from_grade = (bool) $data['excluded_from_grade'];
            }
            if (array_key_exists('title', $data)) {
                $assignment->title = trim($data['title']);
            }
            if (array_key_exists('strictness', $data)) {
                $assignment->strictness = $data['strictness'];
            }
            if (array_key_exists('due_at', $data)) {
                if ($data['due_at'] === null && $assignment->isExam()) {
                    throw ValidationException::withMessages(['due_at' => 'ข้อสอบต้องกำหนดวันสอบ']);
                }
                $assignment->due_at = self::utc($data['due_at']);
            }
            ExamSettings::apply($assignment, $data);
            if (array_key_exists('mode', $data) && $data['mode'] !== $assignment->mode) {
                if ($assignment->isExam()) {
                    throw ValidationException::withMessages(['mode' => 'ข้อสอบไม่ใช้โหมด freeform']);
                }
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
                if ($data['status'] === Assignment::STATUS_DRAFT && $assignment->isManualExam()) {
                    $assignment->status = Assignment::STATUS_READY; // reopened: no key gate (§22.1)
                } elseif ($data['status'] === Assignment::STATUS_DRAFT && ($assignment->isFreeform() || $assignment->isExam())) {
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
            if ($assignment->isExam()) {
                ExamImages::deleteExam($assignment);
            }
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
        return $assignment->load(['classroom', 'subject', 'course', 'lessonPlan', 'googleLink', 'questions.skills', 'questions.rubricCriteria'])
            ->loadCount(['questions', 'responses as missing_ai_key_count' => fn ($q) => $q->awaitingAiKey()]);
    }

    private static function utc(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
