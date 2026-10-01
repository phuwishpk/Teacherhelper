<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Students\SchoolStudents;
use App\Domain\Students\StudentCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A student account of the school (DESIGN §24.4): its editors change the
 * name and the student code, and homeroom teachers see the accounts that
 * look like one child twice.
 */
class StudentController extends Controller
{
    public function __construct(private readonly SchoolStudents $students) {}

    /**
     * PATCH /students/{id} {name?, student_code?} -> {data: student}: only an
     * editor of the student (homeroom teacher of an open classroom of theirs,
     * else 403 not_homeroom_teacher). An empty student_code clears it; one
     * another student holds is 422 student_code_taken with existing_student.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $student = self::schoolStudent($request, $id);
        if ($student->isMerged() || ! Gate::allows('editStudent', $student)) {
            throw self::notHomeroom();
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'student_code' => ['sometimes', 'nullable', 'string', 'max:40'],
        ], [
            'name.required' => 'กรุณากรอกชื่อนักเรียน',
            'student_code.max' => 'เลขประจำตัวยาวเกินไป',
        ]);

        DB::transaction(function () use ($student, $data) {
            if (array_key_exists('name', $data)) {
                $student->name = trim($data['name']);
            }
            if (array_key_exists('student_code', $data)) {
                $code = StudentCode::parse($data['student_code'], 'student_code');
                $holder = $code === null ? null : StudentCode::holder((int) $student->school_id, $code, $student->id);
                if ($holder !== null) {
                    throw StudentCode::taken($holder, $code, 'student_code');
                }
                $student->student_code = $code;
            }
            $student->save();
        });

        return response()->json(['data' => $this->students->payloads(collect([$student->refresh()]))[0]]);
    }

    /**
     * GET /students/duplicate-candidates -> {data: [{a, b, reasons: [google_user|email|name]}]}:
     * the pairs with at least one account in a classroom the teacher is the
     * homeroom teacher of (DESIGN §24.4). A suggestion only.
     */
    public function duplicateCandidates(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Classroom::class);
        $teacher = $request->user();
        $mine = DB::table('classroom_students')
            ->whereIn('classroom_id', Classroom::query()->select('id')->where('teacher_id', $teacher->id)->where('school_id', $teacher->school_id))
            ->distinct()
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return response()->json(['data' => $mine === [] ? [] : $this->students->duplicateCandidates((int) $teacher->school_id, $mine)]);
    }

    /** A student account of the teacher's school (merged ones included), else 404. */
    public static function schoolStudent(Request $request, int $id): User
    {
        return User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('school_id', $request->user()->school_id)
            ->findOrFail($id);
    }

    public static function notHomeroom(): ApiException
    {
        return new ApiException('เฉพาะครูประจำชั้นของนักเรียนคนนี้ที่แก้ข้อมูลได้', 'not_homeroom_teacher', 403);
    }
}
