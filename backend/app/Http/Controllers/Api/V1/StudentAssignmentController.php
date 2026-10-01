<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exams\ExamGuard;
use App\Domain\Pages\PageUploads;
use App\Domain\Pages\WholePageSubmissions;
use App\Domain\Students\StudentClassrooms;
use App\Domain\Students\StudentHandIn;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\ClassroomStudent;
use App\Models\Submission;
use App\Models\SubmissionPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's own hand-ins in the app (DESIGN §19.6, §19.9): the
 * assignments to hand in and the whole-page submission of one. Identity is
 * always the logged-in student (the token), never anything in the request
 * or on the page. Only assignments of classrooms the student is enrolled
 * in exist here (anything else 404).
 *
 * The student sees only that the work was handed in and when; scores and
 * grading progress stay hidden until the teacher publishes (§13).
 */
class StudentAssignmentController extends Controller
{
    /** Most assignments listed at once (a class has a few open ones at a time). */
    public const LIMIT = 100;

    public const STATUS_NOT_SUBMITTED = 'not_submitted';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PUBLISHED = 'published';

    public function __construct(private readonly WholePageSubmissions $submissions) {}

    /**
     * GET /api/v1/student/assignments?course_id=&classroom_id= -> {data:
     * [{id, title, course: {id, code, name}|null, classroom: {id, name,
     * academic_year, closed}, subject_name, due_at, accept_late, can_submit,
     * submission_id, submitted_at, late, status:
     * not_submitted|submitted|published}]} across every classroom of the
     * student (DESIGN §24.11). Only `ready` assignments (a draft freeform
     * one appears once its key is approved, §19.5; closed ones are gone).
     * Soonest due first, no due date last. can_submit = false once the due
     * time passed on an assignment that refuses late work, and always in a
     * closed classroom (read-only, §24.6).
     */
    public function index(Request $request): JsonResponse
    {
        $studentId = (int) $request->user()->id;
        $filters = StudentClassrooms::filters($request);
        $assignments = self::own($request)
            ->where('status', Assignment::STATUS_READY)
            // Exams are done on paper only (DESIGN §22.1).
            ->where('kind', Assignment::KIND_HOMEWORK)
            ->when($filters['course_id'] !== null, fn (Builder $q) => $q->where('course_id', $filters['course_id']))
            ->when($filters['classroom_id'] !== null, fn (Builder $q) => $q->where('classroom_id', $filters['classroom_id']))
            ->with(['classroom:id,name,academic_year,closed_at', 'subject:id,name', 'course:id,code,name'])
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();
        $submissions = Submission::query()
            ->where('student_id', $studentId)
            ->whereIn('assignment_id', $assignments->modelKeys())
            ->get()
            ->keyBy('assignment_id');

        return response()->json(['data' => $assignments->map(function (Assignment $assignment) use ($submissions) {
            /** @var Submission|null $submission */
            $submission = $submissions->get($assignment->id);

            return [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'course' => StudentClassrooms::course($assignment->course),
                'classroom' => StudentClassrooms::label($assignment->classroom),
                'subject_name' => $assignment->subject?->name,
                'due_at' => $assignment->due_at?->toIso8601String(),
                'accept_late' => (bool) $assignment->accept_late,
                'can_submit' => StudentHandIn::canSubmit($assignment),
                'submission_id' => $submission?->id,
                'submitted_at' => $submission?->submitted_at?->toIso8601String(),
                'late' => (bool) ($submission?->late ?? false),
                'status' => self::status($submission),
            ];
        })->values()]);
    }

    /**
     * POST /api/v1/student/assignments/{id}/submission multipart files[]
     * -> 201 {data: {assignment_id, submission_id, submitted_at, late,
     * status: submitted, files, pages}}: the hand-in goes into the
     * whole-page path (§19.4) as the logged-in student. Only a `ready`
     * assignment (409 assignment_not_ready for draft and closed); after the
     * due time it is marked late, or refused with 422 submission_late when
     * the assignment does not accept late work. File rules: PageUploads.
     * A new hand-in after one was graded waits for the teacher's "ตรวจ".
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $student = $request->user();
        $assignment = self::own($request)->findOrFail($id);
        ExamGuard::homeworkOnly($assignment);
        if (! $assignment->isReady()) {
            throw new ApiException('การบ้านนี้ยังไม่เปิดให้ส่ง หรือปิดรับแล้ว', 'assignment_not_ready', 409);
        }
        if (StudentHandIn::refusesLate($assignment)) {
            throw new ApiException('เลยกำหนดส่งแล้ว และการบ้านนี้ไม่รับงานส่งช้า', 'submission_late', 422);
        }

        $files = PageUploads::check($request->file('files'));
        $now = now();
        $received = $this->submissions->receive($assignment, (int) $student->id, $files, SubmissionPage::SOURCE_STUDENT_APP, [
            'uploaded_by' => $student->id,
            'submitted_at' => $now,
            'late' => StudentHandIn::isLate($assignment),
        ], false);
        $submission = $received['submission'];

        return response()->json(['data' => [
            'assignment_id' => $assignment->id,
            'submission_id' => $submission->id,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'late' => (bool) $submission->late,
            'status' => self::STATUS_SUBMITTED,
            'files' => count($files),
            'pages' => array_sum(array_column($files, 'page_count')),
        ]], 201);
    }

    /**
     * Assignments of the classrooms the student is enrolled in.
     *
     * @return Builder<Assignment>
     */
    private static function own(Request $request): Builder
    {
        return Assignment::query()->whereIn(
            'classroom_id',
            ClassroomStudent::query()->select('classroom_id')->where('student_id', $request->user()->id),
        );
    }

    private static function status(?Submission $submission): string
    {
        if ($submission === null) {
            return self::STATUS_NOT_SUBMITTED;
        }

        return $submission->isPublished() ? self::STATUS_PUBLISHED : self::STATUS_SUBMITTED;
    }
}
