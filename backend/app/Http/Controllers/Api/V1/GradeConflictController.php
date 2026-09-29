<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Google\GradeConflicts;
use App\Http\Controllers\Controller;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\GradeConflict;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "คะแนนไม่ตรงกัน" (DESIGN §19.3, §19.9): the grades the teacher changed on
 * the Classroom website, and how each difference is resolved. Scoped to the
 * teacher's own assignments (another teacher's is a 404).
 */
class GradeConflictController extends Controller
{
    public function __construct(private readonly GradeConflicts $conflicts) {}

    /**
     * GET /api/v1/assignments/{id}/grade-conflicts -> {data: [conflict...]},
     * open ones first, then the newest. Each: {id, submission_id, import_id,
     * student: {id, name, student_number}, app_score, classroom_score,
     * status: open|pushed_app|accepted_classroom|dismissed, reason,
     * detected_at, resolved_by, resolved_at, can_push_app, alternate_link}.
     * can_push_app = false for courseWork created on the Classroom website.
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('review', $assignment);

        $rows = GradeConflict::query()
            ->with(['submission.student', 'import'])
            ->whereIn('submission_id', Submission::query()->where('assignment_id', $assignment->id)->select('id'))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();
        $numbers = ClassroomStudent::query()->where('classroom_id', $assignment->classroom_id)->pluck('student_number', 'student_id');
        $web = AssignmentGoogleLink::query()->whereKey($assignment->id)->value('origin') === AssignmentGoogleLink::ORIGIN_CLASSROOM_WEB;

        return response()->json(['data' => $rows->map(fn (GradeConflict $c) => self::toApi($c, $numbers[$c->submission?->student_id] ?? null, $web))->values()]);
    }

    /**
     * POST /api/v1/grade-conflicts/{id}/resolve {action: push_app |
     * accept_classroom | dismiss} -> {data: conflict}. 409 conflict_resolved
     * (not open any more), coursework_not_owned (push_app of courseWork
     * created on the Classroom website), 422 validation_failed.
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $conflict = GradeConflict::query()
            ->with('submission.assignment')
            ->whereIn('submission_id', Submission::query()->whereIn('assignment_id', AssignmentController::ownQuery($request)->select('id'))->select('id'))
            ->findOrFail($id);
        Gate::authorize('review', $conflict->submission->assignment);

        $resolved = $this->conflicts->resolve($conflict, (string) $request->input('action', ''), $request->user());
        $resolved->load(['submission.student', 'import']);
        $number = ClassroomStudent::query()
            ->where('classroom_id', $conflict->submission->assignment->classroom_id)
            ->where('student_id', $conflict->submission->student_id)
            ->value('student_number');
        $web = AssignmentGoogleLink::query()->whereKey($conflict->submission->assignment_id)->value('origin') === AssignmentGoogleLink::ORIGIN_CLASSROOM_WEB;

        return response()->json(['data' => self::toApi($resolved, $number, $web)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toApi(GradeConflict $conflict, mixed $number, bool $web): array
    {
        $student = $conflict->submission?->student;

        return [
            'id' => $conflict->id,
            'submission_id' => $conflict->submission_id,
            'import_id' => $conflict->import_id,
            'student' => $student === null ? null : [
                'id' => $student->id,
                'name' => $student->name,
                'student_number' => $number === null ? null : (int) $number,
            ],
            'app_score' => $conflict->app_score,
            'classroom_score' => $conflict->classroom_score,
            'status' => $conflict->status,
            'reason' => $conflict->reason,
            'detected_at' => $conflict->detected_at?->toIso8601String(),
            'resolved_by' => $conflict->resolved_by,
            'resolved_at' => $conflict->resolved_at?->toIso8601String(),
            'can_push_app' => ! $web,
            'alternate_link' => $conflict->import?->alternate_link,
        ];
    }
}
