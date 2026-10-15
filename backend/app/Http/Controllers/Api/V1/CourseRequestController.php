<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\CourseRequests;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseRequestResource;
use App\Models\ClassroomCourseRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The course requests of shared homerooms (DESIGN §24.7, §24.12 B): the
 * homeroom teacher's incoming box (approve, decline) and the requester's
 * outgoing box (cancel). A request the caller is neither side of is a 404;
 * a decided one is 409 request_closed.
 */
class CourseRequestController extends Controller
{
    /** The newest this many requests of a box. */
    public const LIMIT = 100;

    public function __construct(private readonly CourseRequests $requests) {}

    /**
     * GET /api/v1/course-requests?box=incoming|outgoing&status= -> {data:
     * [request]} newest first. incoming: requests to the caller's homerooms;
     * outgoing: the caller's own requests. status filters
     * (pending|approved|declined|cancelled).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ClassroomCourseRequest::class);
        $teacher = $request->user();
        $data = $request->validate([
            'box' => ['sometimes', 'nullable', Rule::in(['incoming', 'outgoing'])],
            'status' => ['sometimes', 'nullable', Rule::in(ClassroomCourseRequest::STATUSES)],
        ], [
            'box.in' => 'box ต้องเป็น incoming หรือ outgoing',
            'status.in' => 'สถานะไม่ถูกต้อง',
        ]);
        $box = $data['box'] ?? 'incoming';

        $rows = ClassroomCourseRequest::query()
            ->when(
                $box === 'incoming',
                fn (Builder $q) => $q->whereIn('classroom_id', ClassroomAccess::homeroomClassrooms($teacher)->select('classrooms.id'))
                    ->where('origin', '!=', ClassroomCourseRequest::ORIGIN_ADMIN),
                fn (Builder $q) => $q->where('requested_by', $teacher->id),
            )
            ->when(isset($data['status']), fn (Builder $q) => $q->where('status', $data['status']))
            ->with(CourseRequestResource::RELATIONS)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json(['data' => $rows->map(fn (ClassroomCourseRequest $r) => CourseRequestResource::payload($r))->values()->all()]);
    }

    /** POST /api/v1/course-requests/{id}/approve -> {data: request}; the homeroom teacher only. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $row = $this->find($request, $id);
        Gate::authorize('decide', $row);

        return response()->json(['data' => CourseRequestResource::payload($this->requests->approve($request->user(), $row)->refresh())]);
    }

    /** POST /api/v1/course-requests/{id}/decline {reason?} -> {data: request}; the homeroom teacher only. */
    public function decline(Request $request, int $id): JsonResponse
    {
        $row = $this->find($request, $id);
        Gate::authorize('decide', $row);
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], ['reason.max' => 'เหตุผลยาวไม่เกิน 255 ตัวอักษร']);

        return response()->json(['data' => CourseRequestResource::payload($this->requests->decline($request->user(), $row, $data['reason'] ?? null)->refresh())]);
    }

    /** DELETE /api/v1/course-requests/{id} -> 204 (the row stays with status cancelled); the requester only. */
    public function destroy(Request $request, int $id): Response
    {
        $row = $this->find($request, $id);
        Gate::authorize('cancel', $row);
        $this->requests->cancel($request->user(), $row);

        return response()->noContent();
    }

    /** A request the caller sent or that names one of their homerooms (else 404). */
    private function find(Request $request, int $id): ClassroomCourseRequest
    {
        $teacher = $request->user();
        $row = ClassroomCourseRequest::query()
            ->with('classroom')
            ->where(fn (Builder $q) => $q
                ->where('requested_by', $teacher->id)
                ->orWhereIn('classroom_id', ClassroomAccess::homeroomClassrooms($teacher)->select('classrooms.id')))
            ->findOrFail($id);
        Gate::authorize('view', $row);

        return $row;
    }
}
