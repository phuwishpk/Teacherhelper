<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Google\GoogleAccounts;
use App\Domain\Google\GoogleApi;
use App\Domain\Google\GoogleSubmissionSync;
use App\Events\RetakeRequested;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoogleReturnRequest;
use App\Http\Resources\GoogleSubmissionResource;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "ตีกลับให้ถ่ายใหม่" (DESIGN §18.2, §18.6 POST /google-submissions/{id}/return)
 * and "รับงานส่งช้า" (DESIGN §19.3 POST /google-submissions/{id}/accept-late).
 * Classroom's API has no private comments, so the work is returned in
 * Classroom (the student can hand in again) and the Thai reason reaches the
 * student through our app: a push and GET /student/retake-requests.
 */
class GoogleSubmissionController extends Controller
{
    public function __construct(private readonly GoogleAccounts $accounts) {}

    /**
     * POST /api/v1/google-submissions/{id}/accept-late -> 202 {data: row}:
     * the teacher takes a late hand-in the assignment's policy refused
     * (DESIGN §19.3): `rejected_late` becomes `new` with late = TRUE, and the
     * next sync round downloads and grades it as usual. Any other state:
     * 409 import_not_rejected.
     */
    public function acceptLate(Request $request, int $id): JsonResponse
    {
        $import = ClassroomSubmissionImport::query()
            ->with('assignment.classroom')
            ->whereIn('assignment_id', AssignmentController::ownQuery($request)->select('id'))
            ->findOrFail($id);
        Gate::authorize('update', $import);

        $updated = ClassroomSubmissionImport::query()
            ->whereKey($import->id)
            ->where('state', ClassroomSubmissionImport::STATE_REJECTED_LATE)
            ->update(['state' => ClassroomSubmissionImport::STATE_NEW, 'late' => true, 'last_error' => null, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new ApiException('รับได้เฉพาะงานส่งช้าที่ถูกปฏิเสธ', 'import_not_rejected', 409);
        }

        return response()->json(['data' => $this->row($import->refresh())], 202);
    }

    /**
     * {reason} -> {data: row} with state returned_for_retake. 409
     * google_submission_not_returnable (already returned or graded),
     * project_permission_denied (courseWork not created by the app).
     */
    public function returnForRetake(GoogleReturnRequest $request, int $id): JsonResponse
    {
        $import = ClassroomSubmissionImport::query()
            ->with('assignment.classroom')
            ->whereIn('assignment_id', AssignmentController::ownQuery($request)->select('id'))
            ->findOrFail($id);
        Gate::authorize('update', $import);
        // Validation is deferred until after the policy (ValidatesAfterAuthorization);
        // it must still run before anything is sent to Google.
        $reason = (string) $request->validated('reason');

        if (! $import->canReturnForRetake()) {
            throw new ApiException(
                'งานนี้ตีกลับไม่ได้ในสถานะปัจจุบัน (ตีกลับไปแล้ว หรือส่งคะแนนกลับแล้ว)',
                'google_submission_not_returnable',
                409,
            );
        }
        [$posted, $link] = GoogleSubmissionSync::links($import->assignment);

        $this->accounts->call(
            $request->user(),
            fn (GoogleApi $api) => $api->returnSubmission($link->course_id, $posted->course_work_id, $import->google_submission_id),
            'ไม่พบการส่งงานนี้ใน Google Classroom แล้ว กด "ดึงงานที่ส่ง" ใหม่',
        );

        $import->state = ClassroomSubmissionImport::STATE_RETURNED_FOR_RETAKE;
        $import->retake_reason = $reason;
        $import->last_error = null;
        $import->save();
        RetakeRequested::dispatch($import->id);

        return response()->json(['data' => $this->row($import)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ClassroomSubmissionImport $import): array
    {
        $import->load(['student', 'assignment']);
        if ($import->student_id !== null) {
            $import->setAttribute('student_number', ClassroomStudent::query()
                ->where('classroom_id', $import->assignment->classroom_id)
                ->where('student_id', $import->student_id)
                ->value('student_number'));
        }

        return (new GoogleSubmissionResource($import))->resolve(request());
    }
}
