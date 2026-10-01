<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mastery\CourseMasterySummary;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/v1/courses/{id}/mastery-summary?classroom_id=&student_id=&axis=standard|unit
 * (DESIGN §20.3, §20.7): the roll-up of the teacher's own course by
 * standard or by unit, with coverage.
 *
 * - student_id: one student of a classroom of the course (the classroom
 *   given, or any the course is bound to);
 * - classroom_id only: the classroom's values plus each student's.
 *
 * Neither: 422 errors.classroom_id. A classroom not bound to the course,
 * or a student outside it: 422 on that field.
 */
class MasterySummaryController extends Controller
{
    public function __construct(private readonly CourseMasterySummary $summary) {}

    public function __invoke(Request $request, int $id): JsonResponse
    {
        $course = CourseController::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $course);
        $teacher = $request->user();

        $input = Validator::make($request->query(), [
            'axis' => ['sometimes', 'nullable', 'string', Rule::in(CourseMasterySummary::AXES)],
            'classroom_id' => ['required_without:student_id', 'nullable', 'integer'],
            'student_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'axis.in' => 'แกนต้องเป็น standard หรือ unit',
            'classroom_id.required_without' => 'เลือกห้องเรียนหรือนักเรียน',
            'classroom_id.integer' => 'รหัสห้องเรียนไม่ถูกต้อง',
            'student_id.integer' => 'รหัสนักเรียนไม่ถูกต้อง',
        ])->validate();
        $axis = $input['axis'] ?? CourseMasterySummary::AXIS_STANDARD;

        // Every classroom the own course is bound to, as homeroom or subject teacher (§24.8).
        $classrooms = $course->classrooms();
        $classroom = null;
        if (($input['classroom_id'] ?? null) !== null) {
            $classroom = (clone $classrooms)->where('classrooms.id', (int) $input['classroom_id'])->first();
            if ($classroom === null) {
                throw ValidationException::withMessages(['classroom_id' => 'ห้องนี้ไม่ได้เรียนรายวิชานี้']);
            }
        }

        if (($input['student_id'] ?? null) !== null) {
            $roomIds = $classroom !== null ? [$classroom->id] : $classrooms->pluck('classrooms.id')->all();
            $student = User::query()
                ->where('role', User::ROLE_STUDENT)
                ->where('school_id', $teacher->school_id)
                ->whereHas('classrooms', fn ($q) => $q->whereIn('classrooms.id', $roomIds))
                ->find((int) $input['student_id']);
            if ($student === null) {
                throw ValidationException::withMessages(['student_id' => 'นักเรียนคนนี้ไม่ได้อยู่ในห้องของรายวิชานี้']);
            }

            return response()->json(['data' => ['classroom_id' => $classroom?->id] + $this->summary->forStudent($course, $axis, $student)]);
        }

        /** @var Classroom $classroom */
        return response()->json(['data' => $this->summary->forClassroom($course, $axis, $classroom)]);
    }
}
