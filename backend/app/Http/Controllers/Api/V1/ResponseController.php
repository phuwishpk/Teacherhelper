<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Scans\ScanFiles;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Response;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Graded answers (DESIGN §9.5, §9.7).
 */
class ResponseController extends Controller
{
    /**
     * GET /api/v1/responses/{id}/crop[?part=final] -> image/webp of the
     * answer crop (`final`: the final-answer box of a show_work question).
     * The teacher of the classroom, or the student once published.
     * 404 when there is no such crop, 410 image_purged after
     * schools.crop_retention_until (§7.3).
     */
    public function crop(Request $request, int $id): StreamedResponse
    {
        $part = $request->validate(
            ['part' => ['sometimes', 'nullable', 'in:main,final']],
            ['part.in' => 'part ต้องเป็น main หรือ final'],
        )['part'] ?? 'main';

        $response = self::find($request, $id);
        Gate::authorize('viewCrop', $response);

        $final = $part === 'final';
        $path = $final ? $response->final_crop_path : $response->crop_path;
        if ($path === null && $final && $response->crop_path !== null) {
            throw new ApiException('ข้อนี้ไม่มีกรอบคำตอบสุดท้าย', 'not_found', 404);
        }

        $disk = ScanFiles::disk();
        if ($path === null || ! $disk->exists($path)) {
            throw new ApiException('ภาพคำตอบนี้ถูกลบตามนโยบายการเก็บข้อมูลแล้ว', 'image_purged', 410);
        }

        return $disk->response(
            $path,
            "response-{$response->id}".($final ? '-final' : '').'.webp',
            ScanController::imageHeaders(),
        );
    }

    /** Responses in the caller's school; policies decide the rest (other schools get 404). */
    private static function find(Request $request, int $id): Response
    {
        return Response::query()
            ->with('submission.assignment.classroom')
            ->whereIn('submission_id', Submission::query()->select('id')->whereIn(
                'assignment_id',
                Assignment::query()->select('id')->where('school_id', $request->user()->school_id),
            ))
            ->findOrFail($id);
    }
}
