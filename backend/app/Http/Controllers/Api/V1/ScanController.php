<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exams\ExamSheetIngestor;
use App\Domain\Scans\ScanFiles;
use App\Domain\Scans\ScanIngestor;
use App\Domain\Scans\ScanOutcome;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Scan;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Scanned worksheet pages (DESIGN §9.4, §7.3).
 */
class ScanController extends Controller
{
    public function __construct(private readonly ScanIngestor $ingestor) {}

    /**
     * POST /api/v1/scans (multipart: `meta` JSON, `page` WebP, one WebP per
     * crop named in meta) -> 201 active | 202 pending_confirm | 200 replay,
     * body {scan_id, submission_id, state}.
     */
    public function store(Request $request): JsonResponse
    {
        $outcome = $this->ingestor->ingest($request->user(), $request);

        return response()->json($outcome->body(), $outcome->status);
    }

    /**
     * POST /api/v1/scans/{id}/confirm-replace -> 200 {scan_id, submission_id,
     * state: "active"}; 409 scan_superseded / scan_files_missing. A page of
     * an exam answer sheet is confirmed by ExamSheetIngestor (§22.11).
     */
    public function confirmReplace(Request $request, int $id, ExamSheetIngestor $exams): JsonResponse
    {
        $scan = self::find($request, $id);
        if ($scan->submission->assignment->isExam()) {
            Gate::authorize('confirmReplace', $scan);
            $outcome = new ScanOutcome($exams->confirmReplace($request->user(), $scan), 200);

            return response()->json($outcome->body(), $outcome->status);
        }
        $outcome = $this->ingestor->confirmReplace($request->user(), $scan);

        return response()->json($outcome->body(), $outcome->status);
    }

    /**
     * GET /api/v1/scans/{id}/page -> image/webp of the warped page;
     * 410 image_purged once deleted after publishing (§7.3).
     */
    public function page(Request $request, int $id): StreamedResponse
    {
        $scan = self::find($request, $id);
        Gate::authorize('view', $scan);

        $disk = ScanFiles::disk();
        if ($scan->page_image_path === null || ! $disk->exists($scan->page_image_path)) {
            throw new ApiException('ภาพหน้ากระดาษนี้ถูกลบตามนโยบายการเก็บข้อมูลแล้ว', 'image_purged', 410);
        }

        return $disk->response($scan->page_image_path, "scan-{$scan->id}.webp", self::imageHeaders());
    }

    /**
     * Scans of the teacher's school; policies decide the rest (other schools get 404).
     */
    private static function find(Request $request, int $id): Scan
    {
        return Scan::query()
            ->with('submission.assignment.classroom')
            ->whereIn('submission_id', Submission::query()->select('id')->whereIn(
                'assignment_id',
                Assignment::query()->select('id')->where('school_id', $request->user()->school_id),
            ))
            ->findOrFail($id);
    }

    /**
     * @return array<string, string>
     */
    public static function imageHeaders(): array
    {
        return [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
