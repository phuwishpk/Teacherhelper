<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/student/mastery (DESIGN §9.7, §14.2). Placeholder until the
 * mastery step (Phase 6) fills it: an empty list, so the student app can
 * already call it.
 */
class StudentMasteryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [], 'meta' => ['available' => false]]);
    }
}
