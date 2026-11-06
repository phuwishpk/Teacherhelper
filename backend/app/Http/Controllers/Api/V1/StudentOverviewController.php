<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Students\StudentOverview;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/student/overview (DESIGN §24.11) -> {data: {classrooms: [{id,
 * name, academic_year, closed}], groups: [{course: {id, code, name}|null,
 * subject: {id, code, name}|null, classroom: {id, name, academic_year,
 * closed}, teacher_name, todo_count, results_count, latest_published_at,
 * grade: {grade, special}|null}]}}: the signed-in student's classes across
 * every classroom, open classrooms first, then by course code. See
 * StudentOverview.
 */
class StudentOverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['data' => StudentOverview::for($request->user())]);
    }
}
