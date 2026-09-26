<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Review\Appeals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAppealRequest;
use App\Http\Resources\AppealResource;
use App\Http\Resources\StudentResultResource;
use App\Models\ClassroomSubmissionImport;
use App\Models\Question;
use App\Models\Response;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The student's results (DESIGN §9.7, §13): only their own submissions, and
 * only once published. Anything else is 404, so a student cannot even learn
 * that another result exists.
 */
class StudentResultController extends Controller
{
    public const PER_PAGE = 50;

    public function __construct(private readonly Appeals $appeals) {}

    /** GET /api/v1/student/results -> cursor-paginated StudentResultResource, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $page = self::published($request)
            ->with('assignment.subject')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        return StudentResultResource::collection($page);
    }

    /**
     * GET /api/v1/student/results/{submission_id} -> {data: result with
     * responses: per question score, understanding, error types,
     * explanation, crop links, appeal}.
     */
    public function show(Request $request, int $submissionId): StudentResultResource
    {
        $submission = self::published($request)
            ->with(['assignment.subject', 'responses.question', 'responses.appeal'])
            ->findOrFail($submissionId);
        Gate::authorize('view', $submission);
        $submission->setAttribute('retake_reason', ClassroomSubmissionImport::query()
            ->where('assignment_id', $submission->assignment_id)
            ->where('student_id', $submission->student_id)
            ->where('state', ClassroomSubmissionImport::STATE_RETURNED_FOR_RETAKE)
            ->latest('updated_at')
            ->value('retake_reason'));

        return new StudentResultResource($submission);
    }

    /**
     * POST /api/v1/student/responses/{id}/appeal {reason?} -> 201 {data: appeal};
     * once per answer (409 appeal_exists).
     */
    public function appeal(StoreAppealRequest $request, int $responseId): JsonResponse
    {
        $response = Response::query()
            ->with('submission')
            ->whereIn('submission_id', self::published($request)->select('submissions.id'))
            ->findOrFail($responseId);
        Gate::authorize('appeal', $response);

        $appeal = $this->appeals->open($request->user(), $response, $request->validated('reason'));

        return response()->json(['data' => AppealResource::summary($appeal)], 201);
    }

    /**
     * The student's published submissions, with the assignment's full marks.
     *
     * @return Builder<Submission>
     */
    private static function published(Request $request): Builder
    {
        return Submission::query()
            ->select('submissions.*')
            ->selectSub(
                Question::query()->selectRaw('COALESCE(SUM(max_points), 0)')->whereColumn('questions.assignment_id', 'submissions.assignment_id'),
                'max_score',
            )
            ->where('student_id', $request->user()->id)
            ->where('status', Submission::STATUS_PUBLISHED);
    }
}
