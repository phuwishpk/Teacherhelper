<?php

namespace App\Http\Controllers\Api\V1;

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
    public function __construct(private readonly Publisher $publisher) {}

    /**
     * POST /api/v1/submissions/{id}/publish -> {data: {id, assignment_id,
     * student_id, status, total_score, published_at, published_by}};
     * 409 submission_not_reviewed while any answer is not reviewed.
     * Publishing again is a no-op 200.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $submission = Submission::query()
            ->with('assignment.classroom')
            ->whereIn('assignment_id', Assignment::query()->select('id')->where('school_id', $request->user()->school_id))
            ->findOrFail($id);
        Gate::authorize('publish', $submission);

        $submission = $this->publisher->publishSubmission($submission, $request->user());

        return response()->json(['data' => [
            'id' => $submission->id,
            'assignment_id' => $submission->assignment_id,
            'student_id' => $submission->student_id,
            'status' => $submission->status,
            'total_score' => $submission->total_score,
            'published_at' => $submission->published_at?->toIso8601String(),
            'published_by' => $submission->published_by,
        ]]);
    }
}
