<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mastery\ItemAnalysis;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/assignments/{id}/analytics (DESIGN §9.6, §14.3) ->
 * {data: {assignment_id, published_count, min_count_for_r, items: [{question_id,
 * position, type, max_points, prompt_text, n, p, r}], most_missed:
 * [question_id...], skill_error_counts: [{skill, error_type, count}]}}.
 * r is null below min_count_for_r published students.
 */
class AnalyticsController extends Controller
{
    public function assignment(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('viewAnalytics', $assignment);

        return response()->json(['data' => ['assignment_id' => $assignment->id] + ItemAnalysis::forAssignment($assignment)]);
    }
}
