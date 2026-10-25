<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assignments\AssignmentCreator;
use App\Domain\Assignments\AssignmentPosts;
use App\Domain\ClassPosts\AttachmentInput;
use App\Domain\ClassPosts\ClassPosts;
use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Courses\AssignmentCourses;
use App\Domain\Exams\ExamImages;
use App\Domain\Exams\ExamSettings;
use App\Domain\Google\AssignmentGooglePosts;
use App\Domain\Gradebook\AssignmentCategories;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssignmentIndexRequest;
use App\Http\Requests\Api\V1\StoreAssignmentRequest;
use App\Http\Requests\Api\V1\UpdateAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\GradebookEntry;
use App\Models\Unit;
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
            ->with(['classroom', 'subject', 'googleLink', 'course', 'lessonPlan', 'unit'])
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
    public function store(StoreAssignmentRequest $request, AssignmentCreator $creator): JsonResponse
    {
        Gate::authorize('create', Assignment::class);
        $assignment = $creator->create($request->user(), $request->validated(), $request->has('gradebook_category_id'));

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
        $attachments = array_key_exists('attachments', $data) ? AttachmentInput::parse($teacher, $data['attachments'] ?? []) : null;
        DB::transaction(function () use ($assignment, $data, $teacher, $attachments) {
            $assignment = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if (array_key_exists('course_id', $data)) {
                AssignmentCourses::assign($assignment, AssignmentCourses::courseFor($teacher, $assignment->classroom_id, $data['course_id']));
                // A unit of the old course cannot stay.
                if ($assignment->unit_id !== null && Unit::query()->whereKey($assignment->unit_id)->where('course_id', $assignment->course_id)->doesntExist()) {
                    $assignment->unit_id = null;
                }
            }
            if (array_key_exists('unit_id', $data)) {
                [$assignment->unit_id, $assignment->topic] = AssignmentPosts::topic($assignment->course()->first(), $data['unit_id'], $data['topic'] ?? null);
            } elseif (array_key_exists('topic', $data)) {
                // A free topic replaces the unit (one or the other, DESIGN §30.12).
                [$assignment->unit_id, $assignment->topic] = AssignmentPosts::topic(null, null, $data['topic']);
            }
            if (array_key_exists('instructions', $data)) {
                $assignment->instructions = AssignmentPosts::text($data['instructions']);
            }
            if (array_key_exists('send_to_google', $data)) {
                $assignment->send_to_google = (bool) $data['send_to_google'];
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
            if ($attachments !== null) {
                AssignmentPosts::replaceAttachments($assignment, $attachments);
            }
            if (array_key_exists('audience', $data) || array_key_exists('student_ids', $data)) {
                $audience = $data['audience'] ?? (array_key_exists('student_ids', $data) ? Assignment::AUDIENCE_STUDENTS : $assignment->audience);
                AssignmentPosts::setRecipients($assignment, AssignmentPosts::recipients($assignment, $audience, $data['student_ids'] ?? null));
            }
            if (array_key_exists('send_to_google', $data)) {
                // Switched on for ready homework: post it now; off: forget a queued or failed post
                // (a courseWork that exists stays, its submissions refer to it).
                $data['send_to_google'] ? AssignmentGooglePosts::onReady($assignment) : AssignmentGooglePosts::clear($assignment);
            }
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
            $exam = $assignment->isExam();
            // A manual exam is `ready` from creation (DESIGN §22.1), so it is deletable while ready too.
            if (! $assignment->isDraft() && ! ($assignment->isManualExam() && $assignment->isReady())) {
                throw new ApiException($exam ? 'ลบได้เฉพาะข้อสอบที่ยังเป็นฉบับร่าง' : 'ลบได้เฉพาะการบ้านที่ยังเป็นฉบับร่าง', 'assignment_not_draft', 409);
            }
            if ($assignment->worksheetPrints()->exists()) {
                throw new ApiException(
                    $exam ? 'ข้อสอบนี้พิมพ์ไปแล้ว ลบไม่ได้' : 'การบ้านนี้พิมพ์ใบงานไปแล้ว ลบไม่ได้',
                    'assignment_printed',
                    409,
                );
            }
            if ($assignment->isManualExam() && GradebookEntry::query()->where('assignment_id', $assignment->id)
                ->where(fn ($q) => $q->whereNotNull('score')->orWhere('excused', true))->exists()) {
                // The scores typed into the gradebook (§23.3) would go with the exam.
                throw new ApiException('ข้อสอบนี้มีคะแนนในสมุดคะแนนแล้ว ลบไม่ได้', 'exam_scores_entered', 409);
            }
            if ($assignment->googleLink()->exists()) {
                // The courseWork lives on in Classroom and its submissions refer to this row (§18.4).
                throw new ApiException('การบ้านนี้โพสต์ลง Google Classroom แล้ว ลบไม่ได้', 'assignment_posted', 409);
            }
            $fileIds = AttachmentInput::fileIds($assignment->attachments()->get());
            $assignment->delete();
            ClassPosts::deleteUnused($fileIds);
            if ($assignment->isExam()) {
                ExamImages::deleteExam($assignment);
            }
        });

        return response()->noContent();
    }

    /**
     * Assignments the teacher sees (DESIGN §24.8): every assignment of their
     * homerooms and the ones of their own courses elsewhere. The policy then
     * tells reading (view) from managing (update, print, scan, review).
     *
     * @return Builder<Assignment>
     */
    public static function ownQuery(Request $request): Builder
    {
        return ClassroomAccess::assignments($request->user());
    }

    public static function loadDetail(Assignment $assignment): Assignment
    {
        return $assignment->load(['classroom', 'subject', 'course', 'lessonPlan', 'unit', 'attachments.file', 'recipients:users.id', 'googleLink', 'questions.skills', 'questions.rubricCriteria'])
            ->loadCount(['questions', 'responses as missing_ai_key_count' => fn ($q) => $q->awaitingAiKey()]);
    }

    private static function utc(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
