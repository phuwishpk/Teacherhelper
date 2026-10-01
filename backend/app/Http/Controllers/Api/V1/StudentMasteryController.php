<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MasteryResource;
use App\Models\Mastery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/student/mastery (DESIGN §9.7, §14.2): the student's own
 * mastery per skill, weakest first (by value, the order of GET
 * /student/practice), with meta.weaknesses = the three lowest skill ids
 * (§14.3 "จุดอ่อนรายคน").
 */
class StudentMasteryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(self::payload($request->user()->id));
    }

    /**
     * @param  list<int>|null  $allowed  only these indicators (a subject teacher's course, DESIGN §24.8); null = all
     * @return array{data: list<array<string, mixed>>, meta: array{available: bool, weaknesses: list<int>}}
     */
    public static function payload(int $studentId, ?array $allowed = null): array
    {
        $rows = MasteryResource::weakestFirst(Mastery::query()->where('student_id', $studentId)
            ->when($allowed !== null, fn ($q) => $q->whereIn('skill_id', $allowed === [] ? [0] : $allowed))
            ->with('skill')->get());

        return [
            'data' => array_map(fn (Mastery $m) => MasteryResource::row($m), $rows),
            'meta' => [
                'available' => true,
                'weaknesses' => array_map(fn (Mastery $m) => $m->skill_id, array_slice($rows, 0, 3)),
            ],
        ];
    }
}
