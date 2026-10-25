<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomJoiner;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A signed-in pupil joins a classroom with its class code (DESIGN §29.13.3).
 */
class StudentJoinController extends Controller
{
    public function __construct(private readonly ClassroomJoiner $joiner) {}

    /**
     * GET /api/v1/student/join/{code} -> {data: {...public info, member, seats: [{student_id,
     * student_number, name, needs_code}]}}: the seats the teacher made that nobody has used yet.
     */
    public function show(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->joiner->preview($request->user(), ClassroomJoiner::byCode($code))]);
    }

    /**
     * POST /api/v1/student/join {code, student_id?, student_code?} -> 200 {data: {member, joined, student_number}}.
     * student_id claims that seat (the teacher's account for this pupil, merged into theirs);
     * without it the pupil is added with the next number. 409 join_closed / seat_taken,
     * 422 student_code_mismatch / classroom_full.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
            'student_id' => ['sometimes', 'nullable', 'integer'],
            'student_code' => ['sometimes', 'nullable', 'string', 'max:40'],
        ], [
            'code.required' => 'กรอกรหัสห้อง',
            'code.max' => 'รหัสห้องมี 6 ตัวอักษร',
        ]);
        $classroom = ClassroomJoiner::byCode($data['code']);

        return response()->json(['data' => $this->joiner->join(
            $request->user(),
            $classroom,
            isset($data['student_id']) ? (int) $data['student_id'] : null,
            $data['student_code'] ?? null,
        )]);
    }
}
