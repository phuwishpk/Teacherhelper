<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClassroomSubmissionImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/student/retake-requests (DESIGN §18.2, not in §18.6): the
 * student's Google Classroom work the teacher sent back for a new photo,
 * with the reason, newest first:
 * {data: [{id, assignment: {id, title}, reason, requested_at, alternate_link}]}.
 * Only the student's own rows.
 */
class StudentRetakeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewOwn', ClassroomSubmissionImport::class);

        $rows = ClassroomSubmissionImport::query()
            ->with('assignment:id,title')
            ->where('student_id', $request->user()->id)
            ->where('state', ClassroomSubmissionImport::STATE_RETURNED_FOR_RETAKE)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows->map(fn (ClassroomSubmissionImport $row) => [
            'id' => $row->id,
            'assignment' => ['id' => $row->assignment_id, 'title' => $row->assignment?->title],
            'reason' => (string) $row->retake_reason,
            'requested_at' => $row->updated_at?->toIso8601String(),
            'alternate_link' => $row->alternate_link,
        ])->values()]);
    }
}
