<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gradebook\GradebookScores;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Gradebook cells of an assignment (DESIGN §23.3, §23.11): a manual exam
 * takes typed scores and "ให้เต็มทั้งห้อง"; any other assignment only
 * "ยกเว้น" (422 score_from_app). Looked up among the teacher's own
 * assignments (404).
 */
class GradebookScoreController extends Controller
{
    /**
     * PUT /api/v1/assignments/{id}/gradebook-scores {scores: [{student_id,
     * score?, excused?}]} -> {data: {entries: [{student_id, score, excused}]}}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $assignment = $this->assignment($request, $id);

        return response()->json(['data' => ['entries' => GradebookScores::saveForAssignment($assignment, $request->user(), $request->input('scores'))]]);
    }

    /** POST /api/v1/assignments/{id}/gradebook-scores/fill-full -> {data: {filled}} */
    public function fillFull(Request $request, int $id): JsonResponse
    {
        $assignment = $this->assignment($request, $id);

        return response()->json(['data' => ['filled' => GradebookScores::fillAssignment($assignment, $request->user())]]);
    }

    private function assignment(Request $request, int $id): Assignment
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $assignment);

        return $assignment;
    }
}
