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
use Illuminate\Support\Facades\Gate;

/**
 * "ตีกลับให้ถ่ายใหม่" (DESIGN §18.2, §18.6 POST /google-submissions/{id}/return).
 * Classroom's API has no private comments, so the work is returned in
 * Classroom (the student can hand in again) and the Thai reason reaches the
 * student through our app: a push and GET /student/retake-requests.
 */
class GoogleSubmissionController extends Controller
{
    public function __construct(private readonly GoogleAccounts $accounts) {}

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
        $import->retake_reason = (string) $request->validated('reason');
        $import->last_error = null;
        $import->save();
        RetakeRequested::dispatch($import->id);

        $import->load('student');
        if ($import->student_id !== null) {
            $import->setAttribute('student_number', ClassroomStudent::query()
                ->where('classroom_id', $import->assignment->classroom_id)
                ->where('student_id', $import->student_id)
                ->value('student_number'));
        }

        return response()->json(['data' => (new GoogleSubmissionResource($import))->resolve($request)]);
    }
}
