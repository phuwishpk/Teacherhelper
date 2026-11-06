<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exams\ExamGuard;
use App\Domain\Worksheets\LayoutService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\LayoutResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Worksheet layouts (DESIGN §5.3, §9.3).
 */
class LayoutController extends Controller
{
    public function __construct(private readonly LayoutService $layouts) {}

    /**
     * POST /api/v1/assignments/{id}/layout -> 201 {data: layout} for a new
     * version, 200 when the current version already matches the questions.
     * The assignment becomes `ready` and its key approved (DESIGN §19.5).
     * 422 assignment_freeform for a freeform assignment.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('print', $assignment);
        ExamGuard::homeworkOnly($assignment);

        $result = $this->layouts->build($assignment, $request->user()->id);

        return (new LayoutResource($result['layout']))->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    /**
     * GET /api/v1/assignments/{id}/layouts?version= -> {data: layout} for one
     * version (404 layout_unknown if it does not exist), otherwise
     * {data: [layout, ...]} newest first. The app caches these for offline scans.
     */
    public function index(Request $request, int $id): LayoutResource|AnonymousResourceCollection
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $assignment);

        $version = $request->validate(
            ['version' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535']],
            ['version.integer' => 'เวอร์ชันต้องเป็นตัวเลข'],
        )['version'] ?? null;

        if ($version !== null) {
            $layout = $assignment->layouts()->where('version', (int) $version)->first();
            if ($layout === null) {
                throw new ApiException('ไม่พบ layout เวอร์ชันนี้', 'layout_unknown', 404);
            }

            return new LayoutResource($layout);
        }

        return LayoutResource::collection($assignment->layouts()->orderByDesc('version')->get());
    }
}
