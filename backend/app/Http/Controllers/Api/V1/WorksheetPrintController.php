<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exams\ExamGuard;
use App\Domain\Worksheets\WorksheetPrintService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorksheetPrintResource;
use App\Models\Assignment;
use App\Models\WorksheetPrint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Worksheet PDFs (DESIGN §5.5, §9.3): queue, poll, download.
 */
class WorksheetPrintController extends Controller
{
    public function __construct(private readonly WorksheetPrintService $prints) {}

    /** POST /api/v1/assignments/{id}/worksheets -> 202 {data: print} */
    public function store(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('print', $assignment);
        ExamGuard::homeworkOnly($assignment);

        $print = $this->prints->queue($assignment, $request->user());

        return (new WorksheetPrintResource($print->refresh()))->response()->setStatusCode(202);
    }

    /** GET /api/v1/worksheet-prints/{id} -> {data: print} */
    public function show(Request $request, int $id): WorksheetPrintResource
    {
        $print = self::find($request, $id);
        Gate::authorize('view', $print);

        return new WorksheetPrintResource($print);
    }

    /** GET /api/v1/worksheet-prints/{id}/file -> application/pdf | 409 print_not_ready */
    public function download(Request $request, int $id): StreamedResponse
    {
        $print = self::find($request, $id);
        Gate::authorize('download', $print);

        if (! $print->isReady() || ! Storage::disk('local')->exists($print->file_path)) {
            throw new ApiException('ไฟล์ยังไม่พร้อม', 'print_not_ready', 409);
        }

        return Storage::disk('local')->download(
            $print->file_path,
            self::fileName($print),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** worksheets-12-v3.pdf, exam-12-booklet-2.pdf, exam-12-answer-sheets-v1.pdf, exam-12-key-sheet-v1.pdf */
    private static function fileName(WorksheetPrint $print): string
    {
        $id = $print->assignment_id;

        return match ($print->kind) {
            WorksheetPrint::KIND_EXAM_BOOKLET => "exam-{$id}-booklet-{$print->version_no}.pdf",
            WorksheetPrint::KIND_ANSWER_SHEET => "exam-{$id}-answer-sheets-v{$print->layout_version}.pdf",
            WorksheetPrint::KIND_KEY_SHEET => "exam-{$id}-key-sheet-v{$print->layout_version}.pdf",
            default => "worksheets-{$id}-v{$print->layout_version}.pdf",
        };
    }

    private static function find(Request $request, int $id): WorksheetPrint
    {
        return WorksheetPrint::query()
            ->with('assignment.classroom')
            ->whereIn('assignment_id', Assignment::query()->select('id')->where('school_id', $request->user()->school_id))
            ->findOrFail($id);
    }
}
