<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\AnswerKeys\AnswerKeyResult;
use App\Domain\AnswerKeys\AnswerKeyService;
use App\Domain\AnswerKeys\KeyCompleteness;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use App\Models\Assignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The teacher's answer key of an assignment (DESIGN §19.5, §19.9): typed
 * with the question endpoints, read from documents, or drafted by AI, then
 * approved before anything is graded.
 */
class AnswerKeyController extends Controller
{
    public function __construct(private readonly AnswerKeyService $keys) {}

    /**
     * GET /api/v1/assignments/{id}/answer-key -> {data: {assignment_id,
     * mode, status, key_origin, key_approved_at, key_approved_by,
     * extraction_status: queued|done|failed|null, extraction: {id, purpose,
     * status, error, kind, notes_th, created_at, updated_at}|null,
     * key_complete, incomplete_questions: [position], questions: [...]}}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $assignment);

        return response()->json(['data' => self::payload($assignment)]);
    }

    /**
     * POST /api/v1/assignments/{id}/answer-key/extract {document_ids[],
     * page_from?, page_to?}: reads the teacher's key from the documents.
     * Read before in this school -> 200 with the questions filled
     * (extraction.cached = true, no cost); otherwise 202 (ExtractDocumentJob,
     * poll GET .../answer-key). 422 document_too_long (> 30 pages without a
     * range), document_split_unsupported, ai_key_missing, file_too_large,
     * document_missing; 409 assignment_closed.
     */
    public function extract(Request $request, int $id): JsonResponse
    {
        return $this->request($request, $id, AnswerKeyResult::KIND_READ);
    }

    /**
     * POST /api/v1/assignments/{id}/answer-key/draft {document_ids?[],
     * page_from?, page_to?}: no teacher key, AI drafts the answers from the
     * typed questions and/or a question sheet (key_origin = ai_draft). Same
     * answers as extract; 422 assignment_empty without questions or files.
     */
    public function draft(Request $request, int $id): JsonResponse
    {
        return $this->request($request, $id, AnswerKeyResult::KIND_DRAFT);
    }

    /**
     * POST /api/v1/assignments/{id}/answer-key/estimate {kind?: read|draft,
     * document_ids?[], page_from?, page_to?} -> {data: {kind, pages, cached,
     * estimate: {input_tokens, output_tokens, thb}}}: what extract (read,
     * the default) or draft would cost for this selection and whether the
     * school read it before (cached: free). Queues nothing. Same 422s as
     * extract/draft for the selection (document_too_long,
     * validation_failed, assignment_empty).
     */
    public function estimate(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $assignment);

        $kind = $request->input('kind', 'read');
        if (! in_array($kind, ['read', 'draft'], true)) {
            throw new ApiException('ชนิดคำขอไม่ถูกต้อง', 'validation_failed', 422, ['kind' => ['ชนิดคำขอต้องเป็น read หรือ draft']]);
        }
        $kind = $kind === 'read' ? AnswerKeyResult::KIND_READ : AnswerKeyResult::KIND_DRAFT;

        $outcome = $this->keys->estimate($request->user(), $assignment, $kind, $request->only(['document_ids', 'page_from', 'page_to']));

        return response()->json(['data' => $outcome]);
    }

    /**
     * POST /api/v1/assignments/{id}/answer-key/approve {subject_id?} ->
     * {data: answer key}: key_approved_at is set, a freeform draft becomes
     * ready, and hand-ins that waited are graded. subject_id is required
     * (422 course_required) while the assignment has none: a mirror of
     * courseWork created on the Classroom website (DESIGN §19.9).
     * 422 assignment_empty / answer_key_incomplete (errors.questions),
     * 409 assignment_closed.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $assignment);

        $subjectId = $request->input('subject_id');
        if ($subjectId !== null && filter_var($subjectId, FILTER_VALIDATE_INT) === false) {
            throw new ApiException('รหัสวิชาไม่ถูกต้อง', 'validation_failed', 422, ['subject_id' => ['รหัสวิชาไม่ถูกต้อง']]);
        }
        $assignment = $this->keys->approve($request->user(), $assignment, $subjectId === null ? null : (int) $subjectId);

        return response()->json(['data' => self::payload($assignment->refresh())]);
    }

    private function request(Request $request, int $id, string $kind): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $assignment);

        $outcome = $this->keys->request($request->user(), $assignment, $kind, $request->only(['document_ids', 'page_from', 'page_to']));

        return response()->json(['data' => [
            'cached' => $outcome['cached'],
            'estimate' => $outcome['cached'] ? null : $outcome['estimate'],
            'applied' => $outcome['applied'],
            'answer_key' => self::payload($assignment->refresh()),
        ]], $outcome['cached'] ? 200 : 202);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(Assignment $assignment): array
    {
        $assignment->load(['questions.skills', 'questions.rubricCriteria', 'keyExtraction']);
        $extraction = $assignment->keyExtraction;
        $missing = KeyCompleteness::missing($assignment, $assignment->questions);

        return [
            'assignment_id' => $assignment->id,
            'mode' => $assignment->mode,
            'status' => $assignment->status,
            'key_origin' => $assignment->key_origin,
            'key_approved_at' => $assignment->key_approved_at?->toIso8601String(),
            'key_approved_by' => $assignment->key_approved_by,
            'extraction_status' => $extraction?->status,
            'extraction' => $extraction === null ? null : $extraction->toApi() + [
                'kind' => $extraction->result['kind'] ?? null,
                'notes_th' => $extraction->result['notes_th'] ?? null,
            ],
            'key_complete' => $missing === [],
            'incomplete_questions' => $missing === [0] ? [] : $missing,
            'questions' => QuestionResource::collection($assignment->questions),
        ];
    }
}
