<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClosedClassrooms;
use App\Domain\Google\ExamScores;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Exam scores into Google Classroom (DESIGN §30.6, §30.14).
 */
class ExamGoogleController extends Controller
{
    public function __construct(private readonly ExamScores $scores) {}

    /** GET /api/v1/assignments/{id}/google-exam-scores -> {data: status} */
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => ExamScores::status($this->find($request, $id))]);
    }

    /**
     * POST /api/v1/assignments/{id}/google-exam-scores -> 202 {data: {queued,
     * course_work_id}}: makes the holder courseWork on the first send and
     * queues every scored student's score. 409 no_results until the results
     * are out, 422 classroom_not_linked without a link.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $exam = $this->find($request, $id);
        ClosedClassrooms::assertOpen($exam->classroom);

        return response()->json(['data' => $this->scores->send($request->user(), $exam)], 202);
    }

    private function find(Request $request, int $id): Assignment
    {
        $exam = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('manageGoogle', $exam);
        if (! $exam->isExam()) {
            throw new ApiException('คำสั่งนี้ใช้กับข้อสอบเท่านั้น', 'homework_kind_unsupported', 422);
        }

        return $exam;
    }
}
