<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Grading\ScanGrader;
use App\Domain\Scans\SubmissionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Jobs\GradeScanJob;
use App\Models\Response;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * AI grading controls of an assignment (DESIGN §13).
 */
class GradingController extends Controller
{
    public function __construct(private readonly GeminiKeyResolver $keys) {}

    /**
     * POST /api/v1/assignments/{id}/requeue-missing-key -> 202 {data: {requeued: n}}
     * (200 when there was nothing to requeue).
     *
     * The "ตรวจข้อที่ค้างใหม่" button of the missing-key banner: answers that
     * went `manual` because no Gemini key was available (ai_key_missing) or
     * Google refused it (ai_key_invalid), and that the teacher has not graded
     * by hand, go back to `queued` and their scans are graded again. Without
     * a usable key now: 422 ai_key_missing.
     */
    public function requeueMissingKey(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('review', $assignment);

        if ($this->keys->forTeacher($assignment->classroom?->teacher_id) === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }

        $submissionIds = Response::query()
            ->awaitingAiKey()
            ->whereIn('submission_id', $assignment->submissions()->select('id'))
            ->distinct()
            ->pluck('submission_id');

        $scanIds = [];
        $requeued = 0;
        foreach ($submissionIds as $submissionId) {
            DB::transaction(function () use ($submissionId, &$scanIds, &$requeued) {
                $submission = Submission::query()->lockForUpdate()->find($submissionId);
                if ($submission === null || $submission->isPublished()) {
                    return;
                }
                $responses = Response::query()->awaitingAiKey()->where('submission_id', $submissionId)->lockForUpdate()->get();
                foreach ($responses as $response) {
                    $response->forceFill([
                        'grading_state' => Response::STATE_QUEUED,
                        'attempts' => 0,
                        'fuzzy_trace' => null,
                        'review_priority' => null,
                        'priority_band' => null,
                    ])->save();
                    $scanIds[$response->scan_id] = true;
                    $requeued++;
                }
                SubmissionStatus::refresh($submission);
            });
        }

        foreach (array_keys($scanIds) as $scanId) {
            GradeScanJob::dispatch($scanId);
        }

        return response()->json(['data' => [
            'requeued' => $requeued,
            'reasons' => ScanGrader::KEY_REASONS,
        ]], $requeued > 0 ? 202 : 200);
    }
}
