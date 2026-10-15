<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\ClassroomCourseRequest;
use App\Models\ClassroomFeedbackPost;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\GoogleAccount;
use App\Models\GradeConflict;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher home's "รอดำเนินการ" card (DESIGN §19.3, §19.9, §19.11).
 */
class TeacherAttentionController extends Controller
{
    /**
     * GET /api/v1/teacher/attention -> {data: {keys_pending, grade_conflicts,
     * grade_failed, feedback_failed, regrade_pending, pins_pending,
     * needs_reconnect, course_requests_pending}}, over the teacher's own assignments that are not
     * closed, in classrooms that are not closed (pins_pending: over those classrooms):
     *   keys_pending     freeform assignments whose answer key is not
     *                    approved (mirrors of Classroom website courseWork
     *                    included)
     *   grade_conflicts  open "คะแนนไม่ตรงกัน" rows
     *   grade_failed     Classroom submissions whose grade push gave up
     *   feedback_failed  private announcements (§19.7) whose latest publish failed
     *   regrade_pending  submissions whose new hand-in waits for "ตรวจ"
     *   pins_pending     students the background roster sync added whose
     *                    PIN nobody has seen yet (§19.2)
     *   needs_reconnect  the teacher's Google account must be connected again
     *   course_requests_pending  pending requests to bind a course to one of the
     *                    teacher's open homerooms (DESIGN §24.7)
     *
     * Assignments are the ones the teacher manages (DESIGN §24.8), also in
     * classrooms where they are a subject teacher; pins_pending covers their
     * homerooms only.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // A closed classroom (DESIGN §24.6) takes no write, so nothing of it waits for the teacher.
        $teacher = $request->user();
        $openClassrooms = ClassroomAccess::homeroomClassrooms($teacher)->whereNull('closed_at')->select('classrooms.id');
        // The work the teacher manages (§24.8), in any open classroom (homeroom or subject).
        $assignments = ClassroomAccess::managedAssignments($teacher)
            ->where('assignments.status', '!=', Assignment::STATUS_CLOSED)
            ->whereIn('assignments.classroom_id', Classroom::query()->whereNull('closed_at')->select('id'));
        $ids = (clone $assignments)->select('assignments.id');
        $submissionIds = Submission::query()->whereIn('assignment_id', $ids)->select('id');

        return response()->json(['data' => [
            'keys_pending' => (clone $assignments)
                ->where('assignments.mode', Assignment::MODE_FREEFORM)
                ->whereNull('assignments.key_approved_at')
                ->count(),
            'grade_conflicts' => GradeConflict::query()
                ->where('status', GradeConflict::STATUS_OPEN)
                ->whereIn('submission_id', $submissionIds)
                ->count(),
            'grade_failed' => ClassroomSubmissionImport::query()
                ->whereIn('assignment_id', $ids)
                ->where('state', ClassroomSubmissionImport::STATE_GRADE_FAILED)
                ->count(),
            'feedback_failed' => ClassroomFeedbackPost::latestOf($ids)
                ->where('state', ClassroomFeedbackPost::STATE_FAILED)
                ->count(),
            'regrade_pending' => Submission::query()
                ->whereIn('assignment_id', $ids)
                ->where('regrade_pending', true)
                ->count(),
            'pins_pending' => ClassroomStudent::query()
                ->whereNotNull('pin_pending_at')
                ->whereIn('classroom_id', $openClassrooms)
                ->count(),
            'needs_reconnect' => GoogleAccount::query()->find($teacher->id)?->needsReconnect() ?? false,
            // Requests to bind a course to one of the teacher's open homerooms (§24.7).
            'course_requests_pending' => ClassroomCourseRequest::query()
                ->where('status', ClassroomCourseRequest::STATUS_PENDING)
                ->whereIn('classroom_id', $openClassrooms)
                ->count(),
        ]]);
    }
}
