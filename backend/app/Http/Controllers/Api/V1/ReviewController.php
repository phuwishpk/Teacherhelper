<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Review\Publisher;
use App\Domain\Review\ResponseReviewer;
use App\Domain\Review\ReviewQueue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewQueueRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Review and publishing of one assignment (DESIGN §9.5, §13). Assignments of
 * other teachers are 404 (AssignmentController::ownQuery), then
 * AssignmentPolicy::review.
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ResponseReviewer $reviewer,
        private readonly Publisher $publisher,
    ) {}

    /**
     * GET /api/v1/assignments/{id}/review-queue?band=check|look|confident&cursor=&per_page=
     * -> {data: [row], meta: {assignment_id, band, per_page, next_cursor,
     *     missing_ai_key_count, counts: {check|look|confident: {total, unreviewed}},
     *     bulk_approvable_count, submissions: [{id, status, student,
     *     response_count, reviewed_count, question_count, missing_pages,
     *     publishable, open_appeal_count, total_score, published_at}],
     *     pending_confirm_scans: [...]}}
     *
     * Rows: manual first, then suspicious / identity_mismatch, then
     * review_priority descending (ReviewQueue). Each row carries its
     * manual_reason (e.g. ai_key_missing) and flags.
     */
    public function queue(ReviewQueueRequest $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        // Reading the results: the homeroom teacher too, read-only (§24.8).
        Gate::authorize('view', $assignment);

        try {
            $after = ReviewQueue::decodeCursor($request->validated('cursor'));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['cursor' => ['cursor ไม่ถูกต้อง โหลดคิวใหม่ตั้งแต่ต้น']]);
        }

        $band = $request->validated('band');
        $perPage = (int) ($request->validated('per_page') ?? ReviewQueue::PAGE_SIZE);
        $queue = new ReviewQueue($assignment);
        $page = $queue->page($band, $after, $perPage);

        return response()->json([
            'data' => $page['rows'],
            'meta' => [
                'assignment_id' => $assignment->id,
                'band' => $band,
                'per_page' => $perPage,
                'next_cursor' => $page['next_cursor'],
            ] + $queue->meta(),
        ]);
    }

    /**
     * POST /api/v1/assignments/{id}/approve-confident -> {data: {approved: n}}
     * AI values become final for every confident, unflagged, unreviewed
     * answer without an open appeal in unpublished submissions (§13).
     */
    public function approveConfident(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('review', $assignment);

        return response()->json(['data' => [
            'approved' => $this->reviewer->approveConfident($assignment, $request->user()),
        ]]);
    }

    /**
     * POST /api/v1/assignments/{id}/publish
     * -> {data: {published, already_published, skipped}}: publishes every
     * submission whose answers are all reviewed; the rest are skipped.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('review', $assignment);

        return response()->json(['data' => $this->publisher->publishAssignment($assignment, $request->user())]);
    }
}
