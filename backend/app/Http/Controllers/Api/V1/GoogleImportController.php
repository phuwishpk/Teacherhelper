<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Google\ClassroomImporter;
use App\Domain\Google\GoogleRosterSync;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImportGoogleClassroomRequest;
use App\Http\Requests\Api\V1\LinkExistingClassroomRequest;
use App\Http\Resources\ClassroomResource;
use App\Models\Classroom;
use App\Models\GoogleAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Importing a classroom from Google Classroom, binding a course to an
 * existing classroom instead, and syncing a roster (DESIGN §19.2, §19.9, §24.10).
 */
class GoogleImportController extends Controller
{
    /**
     * GET /api/v1/google/courses/{course_id}/import-preview -> {data:
     * {course_id, name, section, suggested_name, grade_level_guess|null,
     * academic_year, students: [{google_user_id, name, email, proposed_number,
     * match: {student_id, name, matched_by: google|classroom_user|email|name,
     * classes: [{id, name, academic_year, student_number, closed}]}|null}],
     * suggested_classroom: {id, name, academic_year, homeroom_teacher: {id,
     * name}, coverage, matched, owned_by_me}|null}} (DESIGN §24.10).
     * 409 course_already_linked; 422 course_id (not an ACTIVE course of the teacher).
     */
    public function preview(Request $request, ClassroomImporter $importer, string $courseId): JsonResponse
    {
        Gate::authorize('manage', GoogleAccount::class);
        Gate::authorize('create', Classroom::class);

        return response()->json(['data' => $importer->preview($request->user(), $courseId)]);
    }

    /**
     * POST /api/v1/classrooms/import-google -> 201 {data: {classroom,
     * students: [{student_id, student_number, name, pin, existing}]}}: the
     * PINs are shown once, like POST /classrooms/{id}/students; a row with
     * student_id enrols that existing student (pin null, existing true,
     * DESIGN §24.10). 422 student_not_in_school, already_enrolled.
     * 409 course_already_linked, course_link_busy (another import or link of
     * the course is running); 422 duplicate student numbers, an account
     * both kept and removed, course_id not an ACTIVE course of the teacher.
     */
    public function import(ImportGoogleClassroomRequest $request, ClassroomImporter $importer): JsonResponse
    {
        Gate::authorize('manage', GoogleAccount::class);
        Gate::authorize('create', Classroom::class);

        $result = $importer->import($request->user(), $request->importInput());
        $classroom = $result['classroom']->loadCount('students')->load('googleLinks.owner:id,name');

        return response()->json(['data' => [
            'classroom' => (new ClassroomResource($classroom))->resolve($request),
            'students' => array_map(fn (array $row) => [
                'student_id' => $row['student']->id,
                'student_number' => $row['student_number'],
                'name' => $row['student']->name,
                'username' => $row['student']->username,
                'pin' => $row['pin'],
                'existing' => $row['existing'],
            ], $result['students']),
        ]], 201);
    }

    /**
     * POST /api/v1/google/courses/{course_id}/link-existing {classroom_id,
     * app_course_id?} (DESIGN §24.10, ClassroomImporter::linkExisting):
     * - 200 {data: {status: linked, classroom, google_link, roster: {added,
     *   enrolled, left, rematched, not_in_classroom}|null, roster_error:
     *   {code, message}|null}} for the homeroom teacher of the classroom (or a
     *   subject teacher whose course is bound to it already);
     * - 202 {data: {status: requested, request_id}} otherwise: a course
     *   request the homeroom teacher approves.
     * 404 classroom of another school; 409 classroom_closed,
     * course_already_linked, course_link_busy, classroom_has_google_posts,
     * request_pending, course_already_in_classroom; 422 course_id, app_course_id.
     */
    public function linkExisting(LinkExistingClassroomRequest $request, ClassroomImporter $importer, string $courseId): JsonResponse
    {
        Gate::authorize('manage', GoogleAccount::class);
        Gate::authorize('create', Classroom::class);
        $appCourseId = $request->validated('app_course_id');

        $result = $importer->linkExisting($request->user(), $courseId, (int) $request->validated('classroom_id'), $appCourseId === null ? null : (int) $appCourseId);
        if ($result['status'] === 'requested') {
            return response()->json(['data' => ['status' => 'requested', 'request_id' => $result['request']->id]], 202);
        }
        $classroom = $result['classroom']->loadCount('students')->load(['googleLinks.owner:id,name', 'teacher:id,name']);

        return response()->json(['data' => [
            'status' => 'linked',
            'classroom' => (new ClassroomResource($classroom))->resolve($request),
            'google_link' => $result['link']->toApi(),
            'roster' => $result['roster'],
            'roster_error' => $result['roster_error'],
        ]]);
    }

    /**
     * POST /api/v1/classrooms/{id}/google-roster/sync -> {data: {added:
     * [{student_id, student_number, name, pin}], enrolled: [{student_id,
     * student_number, name, pin|null}], left: [{student_id, student_number,
     * name}], rematched: [...], not_in_classroom: [{google_user_id, name,
     * email}]}}: the caller's own course (DESIGN §24.10). A subject teacher's
     * course only matches: added and enrolled stay empty and the accounts
     * that are not students of the classroom are in not_in_classroom.
     * 422 classroom_not_linked.
     */
    public function syncRoster(Request $request, GoogleRosterSync $sync, int $id): JsonResponse
    {
        $teacher = $request->user();
        $classroom = ClassroomAccess::classrooms($teacher)->findOrFail($id);
        Gate::authorize('manageGoogle', $classroom);

        return response()->json(['data' => $sync->sync($teacher, $classroom)]);
    }
}
