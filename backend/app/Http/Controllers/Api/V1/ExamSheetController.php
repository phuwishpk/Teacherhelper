<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exams\ExamSheetIngestor;
use App\Domain\Exams\ExamSheetReview;
use App\Http\Controllers\Controller;
use App\Http\Resources\ResponseDetailResource;
use App\Models\Response;
use App\Models\Scan;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Scanned exam answer-sheet pages and their review (DESIGN §22.11). A scan
 * or answer of homework, or of another teacher's exam, is a 404.
 */
class ExamSheetController extends Controller
{
    /**
     * POST /api/v1/exam-sheets (multipart: `meta` JSON {client_scan_id, qr,
     * scanned_at, blur_score, version_fill?, rows, digits?, device_score?},
     * `page` WebP) -> 201 | 202 pending_confirm | 200 replay, body
     * {scan_id, submission_id, state, page_no, page_count, version_no,
     * score, max_score, doubts, needs_version}. See ExamSheetIngestor.
     */
    public function store(Request $request, ExamSheetIngestor $ingestor): JsonResponse
    {
        [$body, $status] = $ingestor->ingest($request->user(), $request);

        return response()->json($body, $status);
    }

    /**
     * POST /api/v1/exam-sheets/{scan_id}/version {version_no} -> 200 the
     * page body of store() after scoring. The teacher picks the version of
     * a page whose version bubble was blank or double (or page 2 without
     * page 1). 409 scan_superseded / submission_published, 422
     * validation_failed (version_no outside 1..version_count).
     */
    public function version(Request $request, int $id, ExamSheetReview $review): JsonResponse
    {
        $scan = Scan::query()
            ->with('submission.assignment.classroom')
            ->whereIn('submission_id', self::ownSubmissions($request))
            ->findOrFail($id);
        // A review write: the exam's manager only (the homeroom teacher reads, §24.8).
        Gate::authorize('confirmReplace', $scan);

        return response()->json($review->chooseVersion($request->user(), $scan, $request->all()));
    }

    /**
     * POST /api/v1/exam-responses/{id}/resolve {options: [positions on the
     * sheet] | value: "12.5"|null} -> {data: response detail}. The answer
     * the teacher read from the marks, scored by code (ExamSheetReview).
     * 409 submission_published, 422 validation_failed.
     */
    public function resolve(Request $request, int $id, ExamSheetReview $review): ResponseDetailResource
    {
        $response = Response::query()
            ->with(['submission.assignment.classroom', 'question'])
            ->whereIn('submission_id', self::ownSubmissions($request))
            ->findOrFail($id);
        Gate::authorize('review', $response);

        $updated = $review->resolve($request->user(), $response, $request->all());

        return new ResponseDetailResource($updated->load([
            'question.rubricCriteria',
            'question.section',
            'submission.student:id,name',
            'submission.assignment:id,classroom_id,title,kind',
            'appeal',
            'scoreEvents',
            'submissionPage:id,mime_type',
        ]));
    }

    /**
     * Submissions of the teacher's own exams.
     *
     * @return Builder<Submission>
     */
    private static function ownSubmissions(Request $request): Builder
    {
        return Submission::query()->select('id')->whereIn('assignment_id', ExamController::ownQuery($request)->select('assignments.id'));
    }
}
