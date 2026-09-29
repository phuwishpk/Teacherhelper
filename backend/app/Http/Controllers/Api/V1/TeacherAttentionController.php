<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\ClassroomFeedbackPost;
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
     * grade_failed, feedback_failed, regrade_pending, needs_reconnect}}, over
     * the teacher's own assignments that are not closed:
     *   keys_pending     freeform assignments whose answer key is not
     *                    approved (mirrors of Classroom website courseWork
     *                    included)
     *   grade_conflicts  open "คะแนนไม่ตรงกัน" rows
     *   grade_failed     Classroom submissions whose grade push gave up
     *   feedback_failed  private announcements (§19.7) whose latest publish failed
     *   regrade_pending  submissions whose new hand-in waits for "ตรวจ"
     *   needs_reconnect  the teacher's Google account must be connected again
     */
    public function __invoke(Request $request): JsonResponse
    {
        $assignments = AssignmentController::ownQuery($request)->where('status', '!=', Assignment::STATUS_CLOSED);
        $ids = (clone $assignments)->select('id');
        $submissionIds = Submission::query()->whereIn('assignment_id', $ids)->select('id');

        return response()->json(['data' => [
            'keys_pending' => (clone $assignments)
                ->where('mode', Assignment::MODE_FREEFORM)
                ->whereNull('key_approved_at')
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
            'needs_reconnect' => GoogleAccount::query()->find($request->user()->id)?->needsReconnect() ?? false,
        ]]);
    }
}
