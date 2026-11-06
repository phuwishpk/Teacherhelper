<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomJoiner;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/join/{code} (DESIGN §29.13.3): the page behind a class code
 * or join QR before the pupil signs in. Public, throttled per address.
 */
class JoinController extends Controller
{
    /** -> {data: {name, grade_level, academic_year, teacher_name, school_name, open}} | 404 classroom_not_found */
    public function show(string $code): JsonResponse
    {
        return response()->json(['data' => ClassroomJoiner::publicInfo(ClassroomJoiner::byCode($code))]);
    }
}
