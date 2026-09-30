<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assignments\AssignmentLocked;
use App\Domain\Exams\ExamEditor;
use App\Domain\Exams\ExamPayload;
use App\Domain\Exams\ExamPrintService;
use App\Domain\Exams\ExamVersions;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorksheetPrintResource;
use App\Models\Assignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Exams of the signed-in teacher (DESIGN §22.15). {id} is an assignment
 * with kind = exam of one of the teacher's classrooms; homework or another
 * teacher's exam is a 404. Sections, the answer key, question approval and
 * the shuffled versions (§22.2–§22.5), and printing (§22.6).
 */
class ExamController extends Controller
{
    public function __construct(private readonly ExamEditor $editor) {}

    /** GET /api/v1/exams/{id} -> {data: exam payload} (ExamPayload::of) */
    public function show(Request $request, int $id): JsonResponse
    {
        $exam = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $exam);

        return response()->json(['data' => ExamPayload::of($exam)]);
    }

    /**
     * POST /api/v1/exams/{id}/sections {title?, instructions?, type:
     * mcq|true_false|numeric, option_count? (mcq 2–6), numeric?: {digits
     * 1–5, allow_negative?, allow_decimal?}, default_points?, question_count?
     * (0–100 blank questions), position?} -> 201 {data: section}. 409
     * exam_structure_locked, 422 too_many_questions / validation_failed.
     */
    public function storeSection(Request $request, int $id): JsonResponse
    {
        $exam = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $exam);

        $section = $this->editor->createSection($exam, $request->only(ExamEditor::SECTION_FIELDS));

        return response()->json(['data' => ExamPayload::section($section)], 201);
    }

    /** POST /api/v1/exams/{id}/questions/approve {question_ids[]} -> {data: exam payload} */
    public function approveQuestions(Request $request, int $id): JsonResponse
    {
        $exam = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $exam);

        $this->editor->approveQuestions($exam, $request->input('question_ids'));

        return response()->json(['data' => ExamPayload::of($exam->refresh())]);
    }

    /**
     * PUT /api/v1/exams/{id}/answer-key {answers: [{question_id,
     * accepted_options?[], accepted_values?[]}]} -> {data: exam payload}.
     * Options are original positions (1 = ก, true_false 1 = ถูก 2 = ผิด);
     * values are numbers that must fit the section's digit block. Errors
     * name the entry: answers.3.accepted_values.0.
     */
    public function answerKey(Request $request, int $id): JsonResponse
    {
        $exam = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $exam);

        $this->editor->saveAnswerKey($exam, $request->input('answers'));

        return response()->json(['data' => ExamPayload::of($exam->refresh())]);
    }

    /** GET /api/v1/exams/{id}/versions -> {data: versions payload} (ExamPayload::versions) */
    public function versions(Request $request, int $id): JsonResponse
    {
        $exam = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $exam);

        // Normally kept up to date by every structural change; rebuilt here if still missing.
        $versions = ExamVersions::ready($exam) || $exam->structureLocked()
            ? ExamVersions::stored($exam)
            : AssignmentLocked::run($exam->id, fn (Assignment $locked) => ExamVersions::sync($locked), allowClosed: true);

        return response()->json(['data' => ExamPayload::versions($exam->refresh(), $versions)]);
    }

    /** POST /api/v1/exams/{id}/versions/reshuffle -> {data: versions payload}; 409 exam_structure_locked */
    public function reshuffle(Request $request, int $id): JsonResponse
    {
        $exam = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $exam);

        $this->editor->reshuffle($exam);
        $exam->refresh();

        return response()->json(['data' => ExamPayload::versions($exam, ExamVersions::stored($exam))]);
    }

    /** POST /api/v1/exams/{id}/unlock-structure -> {data: exam payload}; 409 exam_sheets_scanned */
    public function unlockStructure(Request $request, int $id): JsonResponse
    {
        $exam = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $exam);

        $this->editor->unlockStructure($exam);

        return response()->json(['data' => ExamPayload::of($exam->refresh())]);
    }

    /**
     * POST /api/v1/exams/{id}/prints {kind: exam_booklet|answer_sheet|key_sheet,
     * version_no? (booklet; required with more than one version),
     * student_ids?[] (answer sheets; default the whole roster)} -> 202
     * {data: print}, polled with GET /worksheet-prints/{id}. The first print
     * locks the structure. 409 answer_key_not_approved, 422
     * answer_key_incomplete / exam_sheet_overflow / exam_manual_grading /
     * assignment_empty / classroom_empty / validation_failed, 503
     * qr_key_missing (sheets). See ExamPrintService.
     */
    public function prints(Request $request, int $id, ExamPrintService $prints): JsonResponse
    {
        $exam = self::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('print', $exam);

        $print = $prints->queue($exam, $request->user(), $request->only(['kind', 'version_no', 'student_ids']));

        return (new WorksheetPrintResource($print->refresh()))->response()->setStatusCode(202);
    }

    /**
     * Exams of classrooms the teacher teaches, in the teacher's school.
     *
     * @return Builder<Assignment>
     */
    public static function ownQuery(Request $request): Builder
    {
        return AssignmentController::ownQuery($request)->where('kind', Assignment::KIND_EXAM);
    }
}
