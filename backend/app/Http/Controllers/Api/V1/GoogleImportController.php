<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Google\ClassroomImporter;
use App\Domain\Google\GoogleRosterSync;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ImportGoogleClassroomRequest;
use App\Http\Resources\ClassroomResource;
use App\Models\Classroom;
use App\Models\GoogleAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Importing a classroom from Google Classroom and syncing its roster
 * (DESIGN §19.2, §19.9).
 */
class GoogleImportController extends Controller
{
    /**
     * GET /api/v1/google/courses/{course_id}/import-preview -> {data:
     * {course_id, name, section, suggested_name, grade_level_guess|null,
     * academic_year, students: [{google_user_id, name, email, proposed_number}]}}.
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
     * students: [{student_id, student_number, name, pin}]}}: the PINs are
     * shown once, like POST /classrooms/{id}/students.
     * 409 course_already_linked, course_link_busy (another import or link of
     * the course is running); 422 duplicate student numbers, an account
     * both kept and removed, course_id not an ACTIVE course of the teacher.
     */
    public function import(ImportGoogleClassroomRequest $request, ClassroomImporter $importer): JsonResponse
    {
        Gate::authorize('manage', GoogleAccount::class);
        Gate::authorize('create', Classroom::class);

        $result = $importer->import($request->user(), $request->importInput());
        $classroom = $result['classroom']->loadCount('students')->load('googleLink');

        return response()->json(['data' => [
            'classroom' => (new ClassroomResource($classroom))->resolve($request),
            'students' => array_map(fn (array $row) => [
                'student_id' => $row['student']->id,
                'student_number' => $row['student_number'],
                'name' => $row['student']->name,
                'pin' => $row['pin'],
            ], $result['students']),
        ]], 201);
    }

    /**
     * POST /api/v1/classrooms/{id}/google-roster/sync -> {data: {added:
     * [{student_id, student_number, name, pin}], left: [{student_id,
     * student_number, name}], rematched: [...]}}; 422 classroom_not_linked.
     */
    public function syncRoster(Request $request, GoogleRosterSync $sync, int $id): JsonResponse
    {
        $teacher = $request->user();
        $classroom = ClassroomAccess::classrooms($teacher)->findOrFail($id);
        Gate::authorize('manageGoogle', $classroom);

        return response()->json(['data' => $sync->sync($teacher, $classroom)]);
    }
}
