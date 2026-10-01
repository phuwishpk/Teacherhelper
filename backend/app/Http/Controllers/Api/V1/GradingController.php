<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Exams\ExamGuard;
use App\Domain\Exams\ExamRegrade;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Grading\ClassRegrade;
use App\Domain\Grading\ScanGrader;
use App\Domain\Pages\WholePageSubmissions;
use App\Domain\Review\ReviewFlags;
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
use Illuminate\Support\Facades\Validator;

/**
 * AI grading controls of an assignment (DESIGN §13).
 */
class GradingController extends Controller
{
    public function __construct(
        private readonly GeminiKeyResolver $keys,
        private readonly ClassRegrade $regrade,
        private readonly ExamRegrade $exams,
    ) {}

    /**
     * POST /api/v1/assignments/{id}/regrade {include_overridden?: bool} ->
     * 202 {data: {queued_submissions, skipped_overridden, queued_responses,
     * rescored_by_code, skipped_in_progress, skipped_missing_image,
     * reopened_submissions}} (200 when nothing changed): "ตรวจใหม่ทั้งห้อง"
     * with the current approved key (ClassRegrade, DESIGN §21.13). Published
     * submissions with a changed answer are reopened for review. 409
     * answer_key_not_approved / regrade_in_progress, 422 ai_key_missing /
     * validation_failed. An exam is scored again by code in RescoreExamJob
     * (ExamRegrade, §22.3): no Gemini key, 422 exam_manual_grading.
     */
    public function regrade(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('review', $assignment);

        $outcome = $assignment->isExam()
            ? $this->exams->run($assignment, $request->user(), self::includeOverridden($request))
            : $this->regrade->run($assignment, $request->user(), self::includeOverridden($request));

        return response()->json(['data' => $outcome], $outcome['queued_submissions'] > 0 ? 202 : 200);
    }

    /**
     * POST /api/v1/assignments/{id}/regrade/estimate {include_overridden?}
     * -> {data: {submissions, queued_responses, mcq_by_code,
     * whole_page_pages, skipped_overridden, skipped_in_progress,
     * skipped_missing_image, published_submissions, in_progress, estimate:
     * {input_tokens, output_tokens, thb}}}: what regrade would do and cost
     * (an upper bound). Free, changes nothing. 409 answer_key_not_approved.
     * An exam is always 0 baht (mcq_by_code counts its changed answers).
     */
    public function regradeEstimate(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('review', $assignment);
        $include = self::includeOverridden($request);

        return response()->json(['data' => $assignment->isExam()
            ? $this->exams->estimate($assignment, $include)
            : $this->regrade->estimate($assignment, $include)]);
    }

    private static function includeOverridden(Request $request): bool
    {
        $input = Validator::make($request->all(), [
            'include_overridden' => ['sometimes', 'nullable', 'boolean'],
        ], [
            'include_overridden.boolean' => 'include_overridden ต้องเป็น true หรือ false',
        ])->validate();

        return (bool) ($input['include_overridden'] ?? false);
    }

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
        // Exam answers are scored by code and never wait for a Gemini key (§22.1).
        ExamGuard::homeworkOnly($assignment);

        if ($this->keys->forTeacher(ClassroomAccess::managerId($assignment)) === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }

        $submissionIds = Response::query()
            ->awaitingAiKey()
            ->whereIn('submission_id', $assignment->submissions()->select('id'))
            ->distinct()
            ->pluck('submission_id');

        $scanIds = [];
        $pageIds = [];
        $requeued = 0;
        foreach ($submissionIds as $submissionId) {
            DB::transaction(function () use ($submissionId, &$scanIds, &$pageIds, &$requeued) {
                $submission = Submission::query()->lockForUpdate()->find($submissionId);
                if ($submission === null || $submission->isPublished()) {
                    return;
                }
                $responses = Response::query()->awaitingAiKey()->where('submission_id', $submissionId)->lockForUpdate()->get();
                foreach ($responses as $response) {
                    $response->forceFill([
                        'grading_state' => Response::STATE_QUEUED,
                        'attempts' => 0,
                        'fuzzy_trace' => ReviewFlags::carry($response->fuzzy_trace, null),
                        'review_priority' => null,
                        'priority_band' => null,
                    ])->save();
                    if ($response->scan_id !== null) {
                        $scanIds[$response->scan_id] = true;
                    }
                    $requeued++;
                }
                // Whole-page answers (§19.4): the pages of the round are read again.
                if ($responses->contains(fn (Response $r) => $r->scan_id === null && $r->submission_page_id !== null)) {
                    array_push($pageIds, ...WholePageSubmissions::restartCurrentRound($submission));
                }
                SubmissionStatus::refresh($submission);
            });
        }

        foreach (array_keys($scanIds) as $scanId) {
            GradeScanJob::dispatch($scanId);
        }
        WholePageSubmissions::dispatch($pageIds);

        return response()->json(['data' => [
            'requeued' => $requeued,
            'reasons' => ScanGrader::KEY_REASONS,
        ]], $requeued > 0 ? 202 : 200);
    }
}
