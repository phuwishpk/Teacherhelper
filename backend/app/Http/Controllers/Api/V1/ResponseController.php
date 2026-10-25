<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gemini\TeacherGuidance;
use App\Domain\Review\ResponseReviewer;
use App\Domain\Review\ScoreRules;
use App\Domain\Scans\ScanFiles;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateResponseRequest;
use App\Http\Resources\ResponseDetailResource;
use App\Models\Assignment;
use App\Models\Response;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Graded answers (DESIGN §9.5, §9.7).
 */
class ResponseController extends Controller
{
    public function __construct(private readonly ResponseReviewer $reviewer) {}

    /**
     * GET /api/v1/responses/{id} -> {data: ResponseDetailResource}: the
     * extraction, the raw fuzzy trace and its Thai reading (`why`), the
     * explanation, crop links, the appeal and the score history.
     */
    public function show(Request $request, int $id): ResponseDetailResource
    {
        $response = self::find($request, $id);
        Gate::authorize('view', $response);

        return new ResponseDetailResource(self::loadDetail($response));
    }

    /**
     * PATCH /api/v1/responses/{id} {final_score, final_understanding,
     * final_error_types?, explanation?, reason?} -> {data: detail}.
     * Marks the answer reviewed; `reason` is required when final_score
     * differs from ai_score (422 errors.reason). 409 submission_published /
     * response_grading.
     */
    public function update(UpdateResponseRequest $request, int $id): ResponseDetailResource
    {
        $response = self::find($request, $id);
        Gate::authorize('review', $response);

        if (($error = ScoreRules::invalid((float) $request->validated('final_score'), $response->question)) !== null) {
            throw ValidationException::withMessages(['final_score' => [$error]]);
        }

        $updated = $this->reviewer->review($response, $request->user(), $request->validated());

        return new ResponseDetailResource(self::loadDetail($updated));
    }

    /**
     * POST /api/v1/responses/{id}/regenerate-explanation {guidance?} ->
     * {data: detail} with the new `explanation` (written synchronously).
     * guidance: the teacher's guidance to the AI (DESIGN §21.12, at most 500
     * characters). 422 explanation_unavailable / ai_key_missing /
     * ai_key_invalid / validation_failed (errors.guidance), 502
     * ai_unavailable, 409 submission_published / response_grading.
     */
    public function regenerateExplanation(Request $request, int $id): ResponseDetailResource
    {
        $response = self::find($request, $id);
        Gate::authorize('review', $response);
        $guidance = TeacherGuidance::fromInput($request->all());

        $updated = $this->reviewer->regenerateExplanation($response, $request->user(), $guidance);

        return new ResponseDetailResource(self::loadDetail($updated));
    }

    private static function loadDetail(Response $response): Response
    {
        return $response->load([
            'question.rubricCriteria',
            'submission.student:id,name',
            'submission.assignment:id,classroom_id,title',
            'appeal',
            'scoreEvents',
            'submissionPage:id,mime_type',
        ]);
    }

    /**
     * GET /api/v1/responses/{id}/crop[?part=final] -> image/webp of the
     * answer crop (`final`: the final-answer box of a show_work question).
     * The teacher of the classroom, or the student once published (any
     * other student gets 404). 404 when there is no such crop, 410 image_purged after
     * schools.crop_retention_until (§7.3).
     */
    public function crop(Request $request, int $id): StreamedResponse
    {
        $part = $request->validate(
            ['part' => ['sometimes', 'nullable', 'in:main,final']],
            ['part.in' => 'part ต้องเป็น main หรือ final'],
        )['part'] ?? 'main';

        $response = self::find($request, $id);
        Gate::authorize('viewCrop', $response);

        $final = $part === 'final';
        $path = $final ? $response->final_crop_path : $response->crop_path;
        if ($path === null && $final && $response->crop_path !== null) {
            throw new ApiException('ข้อนี้ไม่มีกรอบคำตอบสุดท้าย', 'not_found', 404);
        }

        $disk = ScanFiles::disk();
        if ($path === null || ! $disk->exists($path)) {
            throw new ApiException('ภาพคำตอบนี้ถูกลบตามนโยบายการเก็บข้อมูลแล้ว', 'image_purged', 410);
        }

        return $disk->response(
            $path,
            "response-{$response->id}".($final ? '-final' : '').'.webp',
            ScanController::imageHeaders(),
        );
    }

    /**
     * Responses in the caller's school; policies decide the rest (other
     * schools get 404). A student only ever finds their own published
     * answers: anything else is 404 like the student-results endpoints, so a
     * student cannot probe which response ids exist.
     */
    private static function find(Request $request, int $id): Response
    {
        $user = $request->user();

        return Response::query()
            ->with(['submission.assignment.classroom', 'question'])
            ->whereIn('submission_id', Submission::query()->select('id')
                ->whereIn('assignment_id', Assignment::query()->select('id')->where('school_id', $user->school_id))
                ->when($user->isStudent(), fn ($q) => $q
                    ->where('student_id', $user->id)
                    ->where('status', Submission::STATUS_PUBLISHED)))
            ->findOrFail($id);
    }
}
