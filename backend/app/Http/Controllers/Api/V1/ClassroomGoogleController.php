<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Google\ClassroomGoogleLinks;
use App\Domain\Google\ClassroomImporter;
use App\Domain\Google\GoogleAccounts;
use App\Domain\Google\GoogleApi;
use App\Domain\Google\GoogleRoster;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoogleLinkRequest;
use App\Http\Requests\Api\V1\GoogleRosterRequest;
use App\Jobs\ClassroomSyncJob;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Linking a classroom to a Google Classroom course and matching its
 * students (DESIGN §18.6, §24.10). Since build 4 every teacher of the
 * classroom (the homeroom teacher and each subject teacher) links their own
 * course, and each route acts on the caller's own link. Classrooms the
 * teacher does not see are a 404; ClassroomPolicy::manageGoogle on top, and
 * pairing accounts by hand stays the homeroom teacher's (editGoogleRoster).
 */
class ClassroomGoogleController extends Controller
{
    public function __construct(
        private readonly GoogleAccounts $accounts,
        private readonly GoogleRoster $roster,
    ) {}

    /**
     * POST /api/v1/classrooms/{id}/google-link {course_id, app_course_id?} ->
     * 201 (200 when it replaced the caller's link) {data: google_link}.
     * The course must be ACTIVE and taught by the teacher (422 course_id);
     * app_course_id is the caller's course bound to the classroom (default:
     * their only one; a subject teacher with several must choose, 422).
     * 409 course_already_linked (another classroom or teacher has it),
     * course_link_busy (another import or link of the course is running),
     * classroom_has_google_posts (moving a teacher whose assignments are
     * posted to another course would strand their courseWork).
     */
    public function link(GoogleLinkRequest $request, int $id): JsonResponse
    {
        $classroom = $this->find($request, $id);
        $teacher = $request->user();
        $courseId = (string) $request->validated('course_id');
        $requested = $request->validated('app_course_id');
        $appCourseId = ClassroomGoogleLinks::appCourseFor($classroom, $teacher, $requested === null ? null : (int) $requested);

        $courses = $this->accounts->call($teacher, fn (GoogleApi $api) => $api->teacherCourses());
        $course = collect($courses)->firstWhere('course_id', $courseId);
        if ($course === null) {
            throw ValidationException::withMessages(['course_id' => ['ไม่พบคอร์สนี้ในคอร์สที่คุณสอนอยู่ (ACTIVE) ใน Google Classroom']]);
        }

        $link = ClassroomImporter::underCourseLock($course['course_id'], fn () => ClassroomGoogleLinks::put($classroom, $teacher, $course, $appCourseId));

        return response()->json(['data' => $link->toApi()], $link->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * DELETE /api/v1/classrooms/{id}/google-link -> 204: the caller's own
     * course. Student matches stay (Google user ids do not change). Grades of
     * assignments already posted cannot be sent back until the same course
     * is linked again.
     */
    public function unlink(Request $request, int $id): Response
    {
        $classroom = $this->find($request, $id);
        ClassroomGoogleLink::query()->where('classroom_id', $classroom->id)->where('owner_user_id', $request->user()->id)->delete();

        return response()->noContent();
    }

    /**
     * GET /api/v1/classrooms/{id}/google-roster -> {data: [{google_user_id,
     * name, email, suggested_student_id, matched_student_id}], meta:
     * {course_id, course_name}}: the caller's course; 422 classroom_not_linked.
     */
    public function roster(Request $request, int $id): JsonResponse
    {
        $classroom = $this->find($request, $id);
        $link = GoogleRoster::linkOf($classroom, $request->user()->id);

        return response()->json([
            'data' => $this->roster->rows($request->user(), $classroom, $link),
            'meta' => ['course_id' => $link->course_id, 'course_name' => $link->course_name],
        ]);
    }

    /**
     * PUT /api/v1/classrooms/{id}/google-roster {matches: [{google_user_id,
     * student_id|null}]} -> the roster after saving. Homeroom teacher only
     * (403 not_homeroom_teacher). 422 when an account is not in the course,
     * a student not in the classroom, or one student is given two accounts.
     */
    public function saveRoster(GoogleRosterRequest $request, int $id): JsonResponse
    {
        $classroom = $this->find($request, $id);
        Gate::authorize('editGoogleRoster', $classroom);
        $link = GoogleRoster::linkOf($classroom, $request->user()->id);

        return response()->json([
            'data' => $this->roster->save($request->user(), $classroom, $link, $request->matches()),
            'meta' => ['course_id' => $link->course_id, 'course_name' => $link->course_name],
        ]);
    }

    /**
     * POST /api/v1/classrooms/{id}/google-sync -> 202 {data: {queued: true}}:
     * "ซิงก์ตอนนี้", one sync round of this classroom now (DESIGN §19.3):
     * new courseWork from the Classroom website, hand-ins and grades of every
     * course linked to it. 422 classroom_not_linked (the caller has no course
     * here); 409 google_not_connected / google_reconnect_required for the
     * caller's Google account.
     */
    public function syncNow(Request $request, int $id): JsonResponse
    {
        $classroom = $this->find($request, $id);
        GoogleRoster::linkOf($classroom, $request->user()->id);
        $this->accounts->accountOf($request->user());
        ClassroomSyncJob::dispatch($classroom->id);

        return response()->json(['data' => ['queued' => true]], 202);
    }

    private function find(Request $request, int $id): Classroom
    {
        // Seen as homeroom or subject teacher (else 404); each links their own course (§24.10).
        $classroom = ClassroomAccess::classrooms($request->user())->findOrFail($id);
        Gate::authorize('manageGoogle', $classroom);

        return $classroom;
    }
}
