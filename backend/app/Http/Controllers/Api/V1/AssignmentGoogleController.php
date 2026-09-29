<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Google\ClassroomFeedback;
use App\Domain\Google\CourseWorkPoster;
use App\Domain\Google\GoogleSubmissionSync;
use App\Domain\Google\GradeConflicts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GooglePostRequest;
use App\Http\Resources\GoogleSubmissionResource;
use App\Jobs\PostClassroomFeedbackJob;
use App\Jobs\PushClassroomGradeJob;
use App\Models\Assignment;
use App\Models\ClassroomFeedbackPost;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * An assignment in Google Classroom (DESIGN §18.2, §18.6): post it, sync
 * the submissions, send failed grades again. Scoped to the teacher's own
 * assignments (another teacher's is a 404), AssignmentPolicy::manageGoogle
 * on top.
 */
class AssignmentGoogleController extends Controller
{
    public function __construct(
        private readonly CourseWorkPoster $poster,
        private readonly GoogleSubmissionSync $sync,
    ) {}

    /**
     * POST /api/v1/assignments/{id}/google-post {attach_blank_worksheet,
     * instructions?, due_at?} -> 201 {data: {course_work_id, alternate_link,
     * drive_file_id, has_blank_worksheet, posted_at}}. 409 already_posted /
     * assignment_not_ready, 422 classroom_not_linked.
     */
    public function post(GooglePostRequest $request, int $id): JsonResponse
    {
        $assignment = $this->find($request, $id);
        $link = $this->poster->post($request->user(), $assignment, $request->postInput());

        return response()->json(['data' => $link->toApi()], 201);
    }

    /**
     * GET /api/v1/assignments/{id}/google-submissions: syncs the TURNED_IN
     * submissions from Classroom, then {data: [row...], meta: {next_cursor:
     * null, synced_at}} (one page: a class is small). 409 not_posted.
     */
    public function submissions(Request $request, int $id): JsonResponse
    {
        $assignment = $this->find($request, $id);
        $rows = $this->sync->sync($request->user(), $assignment);

        return response()->json([
            'data' => GoogleSubmissionResource::collection($rows)->resolve($request),
            'meta' => ['next_cursor' => null, 'synced_at' => now()->toIso8601String()],
        ]);
    }

    /**
     * POST /api/v1/assignments/{id}/google-grades/retry -> 202 {data: {queued}}:
     * a PushClassroomGradeJob for every published submission whose grade did
     * not reach Classroom (grade_failed, or a matched student without a row).
     * 409 coursework_not_owned for courseWork created on the Classroom website.
     */
    public function retryGrades(Request $request, int $id): JsonResponse
    {
        $assignment = $this->find($request, $id);
        [$posted] = GoogleSubmissionSync::links($assignment);
        if ($posted->isFromClassroomWeb()) {
            throw GradeConflicts::notOwned();
        }

        $published = Submission::query()
            ->where('assignment_id', $assignment->id)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->get(['id', 'student_id']);
        $imports = ClassroomSubmissionImport::query()
            ->where('assignment_id', $assignment->id)
            ->whereNotNull('student_id')
            ->get(['student_id', 'state'])
            ->groupBy('student_id');
        $matched = ClassroomStudent::query()
            ->where('classroom_id', $assignment->classroom_id)
            ->whereNotNull('google_user_id')
            ->pluck('student_id')
            ->flip();

        $queued = 0;
        foreach ($published as $submission) {
            $rows = $imports->get($submission->student_id);
            $failed = $rows !== null && $rows->contains('state', ClassroomSubmissionImport::STATE_GRADE_FAILED);
            $neverSent = $rows === null && isset($matched[$submission->student_id]);
            if ($failed || $neverSent) {
                PushClassroomGradeJob::dispatch($submission->id);
                $queued++;
            }
        }

        return response()->json(['data' => ['queued' => $queued]], 202);
    }

    /**
     * GET /api/v1/assignments/{id}/google-feedback -> {data: [{id,
     * submission_id, student: {id, name, student_number}, published_at,
     * state: queued|posted|failed, last_error, posted_at, announcement_id}]}:
     * the private announcement of each student's latest publish (DESIGN
     * §19.7), by student number. No Google call.
     */
    public function feedback(Request $request, int $id): JsonResponse
    {
        $assignment = $this->find($request, $id);
        $numbers = ClassroomStudent::query()->where('classroom_id', $assignment->classroom_id)->pluck('student_number', 'student_id');
        $rows = ClassroomFeedbackPost::latestOf([$assignment->id])
            ->with('submission.student:id,name')
            ->get()
            ->sortBy(function (ClassroomFeedbackPost $post) use ($numbers) {
                $number = $numbers[$post->submission?->student_id] ?? null;

                return [$number === null ? 1 : 0, (int) $number, $post->id];
            })
            ->values();

        return response()->json(['data' => $rows->map(fn (ClassroomFeedbackPost $post) => [
            'id' => $post->id,
            'submission_id' => $post->submission_id,
            'student' => [
                'id' => $post->submission?->student_id,
                'name' => $post->submission?->student?->name,
                'student_number' => $numbers[$post->submission?->student_id] ?? null,
            ],
            'published_at' => $post->published_at->toIso8601String(),
            'state' => $post->state,
            'last_error' => $post->last_error,
            'posted_at' => $post->posted_at?->toIso8601String(),
            'announcement_id' => $post->announcement_id,
        ])->all()]);
    }

    /**
     * POST /api/v1/assignments/{id}/google-feedback/retry -> 202 {data:
     * {queued}}: the failed announcements (latest publish of each student)
     * go back to the queue with the student's current Classroom match. A
     * row still without a match stays failed with the reason. No Google call.
     */
    public function retryFeedback(Request $request, int $id): JsonResponse
    {
        $assignment = $this->find($request, $id);
        $queued = 0;
        foreach (ClassroomFeedbackPost::latestOf([$assignment->id])->where('state', ClassroomFeedbackPost::STATE_FAILED)->get() as $post) {
            if (ClassroomFeedback::requeue($post)) {
                PostClassroomFeedbackJob::dispatch($post->id);
                $queued++;
            }
        }

        return response()->json(['data' => ['queued' => $queued]], 202);
    }

    private function find(Request $request, int $id): Assignment
    {
        $assignment = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('manageGoogle', $assignment);

        return $assignment;
    }
}
