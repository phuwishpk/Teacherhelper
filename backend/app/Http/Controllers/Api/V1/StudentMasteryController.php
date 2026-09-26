<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MasteryResource;
use App\Models\Mastery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/student/mastery (DESIGN §9.7, §14.2): the student's own
 * mastery per skill, weakest first, with meta.weaknesses = the three
 * lowest skill ids (§14.3 "จุดอ่อนรายคน").
 */
class StudentMasteryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(self::payload($request->user()->id));
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{available: bool, weaknesses: list<int>}}
     */
    public static function payload(int $studentId): array
    {
        $rows = MasteryResource::weakestFirst(Mastery::query()->where('student_id', $studentId)->with('skill')->get());

        return [
            'data' => array_map(fn (Mastery $m) => MasteryResource::row($m), $rows),
            'meta' => [
                'available' => true,
                'weaknesses' => array_map(fn (Mastery $m) => $m->skill_id, array_slice($rows, 0, 3)),
            ],
        ];
    }
}
