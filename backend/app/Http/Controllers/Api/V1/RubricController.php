<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assignments\RubricService;
use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use App\Jobs\DraftRubricJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Rubrics of show_work and open questions (DESIGN §9.3, §10.4).
 */
class RubricController extends Controller
{
    public function __construct(
        private readonly RubricService $rubrics,
        private readonly GeminiKeyResolver $keys,
    ) {}

    /**
     * POST /api/v1/questions/{id}/rubric/draft -> 202 {data: question}.
     * Queues DraftRubricJob; the app polls GET /assignments/{id} until the
     * draft criteria / reference steps appear. Without any Gemini key
     * (teacher or server, DESIGN §10.1) nothing is queued: 422 ai_key_missing.
     */
    public function draft(Request $request, int $id): JsonResponse
    {
        $question = QuestionController::ownQuery($request)->findOrFail($id);
        Gate::authorize('manageRubric', $question);

        if (! $question->needsRubric()) {
            throw new ApiException('ข้อประเภทนี้ไม่ใช้ rubric', 'rubric_not_needed', 422);
        }
        if ($question->assignment->isClosed()) {
            throw new ApiException('การบ้านนี้ปิดแล้ว แก้ไขไม่ได้', 'assignment_closed', 409);
        }

        if ($this->keys->forTeacher(ClassroomAccess::managerId($question->assignment)) === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }

        DraftRubricJob::dispatch($question->id);

        return (new QuestionResource(QuestionController::loadDetail($question)))->response()->setStatusCode(202);
    }

    /** PUT /api/v1/questions/{id}/rubric {criteria: [...], reference_steps?: [...]} -> {data: question} (approved) */
    public function update(Request $request, int $id): QuestionResource
    {
        $question = QuestionController::ownQuery($request)->findOrFail($id);
        Gate::authorize('manageRubric', $question);

        $question = $this->rubrics->approve($question, $request->only(['criteria', 'reference_steps']));

        return new QuestionResource(QuestionController::loadDetail($question));
    }
}
