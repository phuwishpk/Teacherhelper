<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pages\WholePageSubmissions;
use App\Domain\Review\Publisher;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Submissions (DESIGN §9.5). */
class SubmissionController extends Controller
{
    public function __construct(
        private readonly Publisher $publisher,
        private readonly WholePageSubmissions $wholePage,
    ) {}

    /**
     * POST /api/v1/submissions/{id}/grade -> 202 {data: {id, status,
     * regrade_pending, pages}}: grades a new whole-page hand-in that waits
     * for the teacher (regrade_pending, DESIGN §19.4). A published
     * submission is reopened. 409 nothing_to_grade when no hand-in waits,
     * answer_key_not_approved before the teacher approved the key (§19.5).
     */
    public function grade(Request $request, int $id): JsonResponse
    {
        $submission = $this->find($request, $id);
        Gate::authorize('grade', $submission);

        $pages = $this->wholePage->start($submission);
        $submission->refresh();

        return response()->json(['data' => [
            'id' => $submission->id,
            'status' => $submission->status,
            'regrade_pending' => $submission->regrade_pending,
            'pages' => $pages,
        ]], 202);
    }

    /**
     * POST /api/v1/submissions/{id}/publish -> {data: {id, assignment_id,
     * student_id, status, total_score, total_override, published_at,
     * published_by}} (total_score: the effective total, DESIGN §19.3);
     * 409 submission_not_reviewed while any answer is not reviewed.
     * Publishing again is a no-op 200.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $submission = $this->find($request, $id);
        Gate::authorize('publish', $submission);

        $submission = $this->publisher->publishSubmission($submission, $request->user());

        return response()->json(['data' => [
            'id' => $submission->id,
            'assignment_id' => $submission->assignment_id,
            'student_id' => $submission->student_id,
            'status' => $submission->status,
            'total_score' => $submission->effectiveTotal(),
            'total_override' => $submission->total_override,
            'published_at' => $submission->published_at?->toIso8601String(),
            'published_by' => $submission->published_by,
        ]]);
    }

    /** Submissions of the caller's school; policies decide the rest (other schools get 404). */
    private function find(Request $request, int $id): Submission
    {
        return Submission::query()
            ->with('assignment.classroom')
            ->whereIn('assignment_id', Assignment::query()->select('id')->where('school_id', $request->user()->school_id))
            ->findOrFail($id);
    }
}
