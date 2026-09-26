<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Google\GoogleAccounts;
use App\Domain\Google\GoogleApi;
use App\Domain\Google\GoogleRoster;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoogleLinkRequest;
use App\Http\Requests\Api\V1\GoogleRosterRequest;
use App\Models\AssignmentGoogleLink;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Linking a classroom to a Google Classroom course and matching its
 * students (DESIGN §18.6). Scoped to the teacher's own classrooms (another
 * teacher's is a 404), with ClassroomPolicy::manageGoogle on top.
 */
class ClassroomGoogleController extends Controller
{
    public function __construct(
        private readonly GoogleAccounts $accounts,
        private readonly GoogleRoster $roster,
    ) {}

    /**
     * POST /api/v1/classrooms/{id}/google-link {course_id} -> 201 (200 when
     * it replaced a link) {data: {course_id, course_name, linked_at}}.
     * The course must be ACTIVE and taught by the teacher (422 course_id).
     * 409 course_already_linked (another classroom has it),
     * classroom_has_google_posts (moving a room whose assignments are posted
     * to another course would strand their courseWork).
     */
    public function link(GoogleLinkRequest $request, int $id): JsonResponse
    {
        $classroom = $this->find($request, $id);
        $courseId = (string) $request->validated('course_id');

        $courses = $this->accounts->call($request->user(), fn (GoogleApi $api) => $api->teacherCourses());
        $course = collect($courses)->firstWhere('course_id', $courseId);
        if ($course === null) {
            throw ValidationException::withMessages(['course_id' => ['ไม่พบคอร์สนี้ในคอร์สที่คุณสอนอยู่ (ACTIVE) ใน Google Classroom']]);
        }

        $link = DB::transaction(function () use ($request, $classroom, $course) {
            $current = ClassroomGoogleLink::query()->lockForUpdate()->find($classroom->id);
            if ($current !== null && $current->course_id !== $course['course_id'] && $this->hasPosts($classroom)) {
                throw new ApiException(
                    'ห้องเรียนนี้มีการบ้านที่โพสต์ลงคอร์สเดิมแล้ว เปลี่ยนไปผูกคอร์สอื่นไม่ได้',
                    'classroom_has_google_posts',
                    409,
                );
            }
            $taken = ClassroomGoogleLink::query()
                ->where('course_id', $course['course_id'])
                ->where('classroom_id', '!=', $classroom->id)
                ->exists();
            if ($taken) {
                throw new ApiException('คอร์สนี้ผูกกับห้องเรียนอื่นในระบบแล้ว', 'course_already_linked', 409);
            }

            return ClassroomGoogleLink::query()->updateOrCreate(['classroom_id' => $classroom->id], [
                'course_id' => $course['course_id'],
                'course_name' => mb_substr($course['name'] !== '' ? $course['name'] : $course['course_id'], 0, 255),
                'owner_user_id' => $request->user()->id,
                'linked_at' => now(),
            ]);
        });

        return response()->json(['data' => $link->toApi()], $link->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * DELETE /api/v1/classrooms/{id}/google-link -> 204. Student matches stay
     * (Google user ids do not change). Grades of assignments already posted
     * cannot be sent back until the same course is linked again.
     */
    public function unlink(Request $request, int $id): Response
    {
        $classroom = $this->find($request, $id);
        ClassroomGoogleLink::query()->whereKey($classroom->id)->delete();

        return response()->noContent();
    }

    /**
     * GET /api/v1/classrooms/{id}/google-roster -> {data: [{google_user_id,
     * name, email, suggested_student_id, matched_student_id}], meta:
     * {course_id, course_name}}; 422 classroom_not_linked.
     */
    public function roster(Request $request, int $id): JsonResponse
    {
        $classroom = $this->find($request, $id);
        $link = GoogleRoster::linkOf($classroom);

        return response()->json([
            'data' => $this->roster->rows($request->user(), $classroom, $link),
            'meta' => ['course_id' => $link->course_id, 'course_name' => $link->course_name],
        ]);
    }

    /**
     * PUT /api/v1/classrooms/{id}/google-roster {matches: [{google_user_id,
     * student_id|null}]} -> the roster after saving. 422 when an account is
     * not in the course, a student not in the classroom, or one student is
     * given two accounts.
     */
    public function saveRoster(GoogleRosterRequest $request, int $id): JsonResponse
    {
        $classroom = $this->find($request, $id);
        $link = GoogleRoster::linkOf($classroom);

        return response()->json([
            'data' => $this->roster->save($request->user(), $classroom, $link, $request->matches()),
            'meta' => ['course_id' => $link->course_id, 'course_name' => $link->course_name],
        ]);
    }

    private function find(Request $request, int $id): Classroom
    {
        $teacher = $request->user();
        $classroom = Classroom::query()
            ->where('school_id', $teacher->school_id)
            ->where('teacher_id', $teacher->id)
            ->findOrFail($id);
        Gate::authorize('manageGoogle', $classroom);

        return $classroom;
    }

    private function hasPosts(Classroom $classroom): bool
    {
        return AssignmentGoogleLink::query()->whereIn('assignment_id', $classroom->assignments()->select('id'))->exists();
    }
}
