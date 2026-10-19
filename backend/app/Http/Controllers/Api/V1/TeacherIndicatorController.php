<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Skills\TeacherIndicators;
use App\Http\Controllers\Controller;
use App\Http\Resources\SkillResource;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "ตัวชี้วัดของฉัน" (DESIGN §34):
 *
 * - GET /me/indicators -> {data: [skill]} the teacher's set, by subject, grade and code;
 * - PUT /me/indicators {skill_ids: [int]} replaces it (an empty list clears it) and answers the same.
 */
class TeacherIndicatorController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return self::listing(TeacherIndicators::ids($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        return self::listing(TeacherIndicators::replace($request->user(), $request->input('skill_ids')));
    }

    /** @param  list<int>  $ids */
    private static function listing(array $ids): JsonResponse
    {
        $skills = $ids === [] ? collect() : Skill::query()->whereIn('id', $ids)->notTrashed()
            ->orderBy('subject_id')->orderBy('grade_level')->orderBy('code')->orderBy('id')->get();

        return response()->json(['data' => SkillResource::collection($skills)->resolve()]);
    }
}
