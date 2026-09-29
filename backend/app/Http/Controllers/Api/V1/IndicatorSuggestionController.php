<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Courses\IndicatorMapping;
use App\Domain\Courses\IndicatorSuggestions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Indicators of an assignment's questions (DESIGN §20.3, §20.7): Gemini
 * suggests from the linked lesson plan, the teacher confirms or edits, and
 * questions still without an indicator are a warning, never a block.
 */
class IndicatorSuggestionController extends Controller
{
    public function __construct(
        private readonly IndicatorSuggestions $suggestions,
        private readonly IndicatorMapping $mapping,
    ) {}

    /**
     * POST /api/v1/assignments/{id}/indicator-suggestions -> 202 {data:
     * {status, requested_at, finished_at, error, suggested_question_count,
     * dropped_code_count}}; 422 lesson_plan_required | lesson_plan_no_indicators
     * | no_questions | ai_key_missing. The app polls the GET below.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('update', $assignment);

        return response()->json(['data' => $this->suggestions->request($assignment)], 202);
    }

    /**
     * GET /api/v1/assignments/{id}/indicator-suggestions -> {data:
     * {assignment_id, lesson_plan, plan_indicators[], status, requested_at,
     * finished_at, error, suggested_question_count, dropped_code_count,
     * questions: [{question_id, position, type, prompt_text, skill_ids,
     * skills[], suggestions: [{skill, reason_th}]}], unmapped_question_count,
     * unmapped_warning}}
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $assignment);

        return response()->json(['data' => $this->suggestions->payload($assignment)]);
    }

    /**
     * PUT /api/v1/assignments/{id}/indicator-mapping {questions:
     * [{question_id, skill_ids[]}]} -> the GET payload plus changed_question_count
     */
    public function mapping(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $assignment);

        $changed = $this->mapping->apply($assignment, $request->all());

        return response()->json(['data' => $this->suggestions->payload($assignment->refresh()) + ['changed_question_count' => $changed]]);
    }
}
