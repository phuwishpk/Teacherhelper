<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exams\ExamSheetIngestor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scanned exam answer-sheet pages (DESIGN §22.11).
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
}
