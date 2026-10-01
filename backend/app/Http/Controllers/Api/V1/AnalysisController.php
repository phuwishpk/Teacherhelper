<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analysis\StudentAnalyses;
use App\Domain\Classrooms\ClosedClassrooms;
use App\Domain\Gemini\TeacherGuidance;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentAnalysisPayload;
use App\Models\Classroom;
use App\Models\StudentAnalysis;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The per-student analysis (DESIGN §20.5, §20.7). Teachers see and edit the
 * analyses of their own classrooms (others 404); the student text reaches
 * the student only through approval or the classroom's auto-share, and the
 * student endpoint returns their own shared texts only (§20.9).
 */
class AnalysisController extends Controller
{
    public function __construct(private readonly StudentAnalyses $analyses) {}

    /**
     * GET /api/v1/classrooms/{id}/analyses -> {data: {classroom_id,
     * auto_share_analysis, students: [{student: {id, name, student_number},
     * analysis: summary|null}]}} in student-number order.
     */
    public function classroom(Request $request, int $id): JsonResponse
    {
        $classroom = ChartController::ownClassroom($request, $id);
        $students = $classroom->students()->get(['users.id', 'users.name']);
        $rows = StudentAnalysis::query()->where('classroom_id', $classroom->id)->get()->keyBy('student_id');
        $skills = StudentAnalysisPayload::skillsOf($rows);

        return response()->json(['data' => [
            'classroom_id' => $classroom->id,
            'auto_share_analysis' => (bool) $classroom->auto_share_analysis,
            'students' => $students->map(fn (User $s) => [
                'student' => ['id' => $s->id, 'name' => $s->name, 'student_number' => (int) $s->pivot->student_number],
                'analysis' => isset($rows[$s->id]) ? StudentAnalysisPayload::summary($rows[$s->id], $skills) : null,
            ])->values()->all(),
        ]]);
    }

    /**
     * GET /api/v1/students/{id}/analysis?classroom_id= -> {data: teacher
     * payload|null}. Strengths and areas are recomputed from the current
     * mastery on every read; null = nothing assessed in that classroom yet.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        [$student, $classroom] = $this->studentAndClassroom($request, $id, $request->query());
        $row = $this->analyses->current($student, $classroom);

        return response()->json(['data' => $row === null ? null : self::teacherPayload($row)]);
    }

    /**
     * POST /api/v1/students/{id}/analysis/run {classroom_id, guidance?} ->
     * {data: teacher payload (with `guidance`)}: "วิเคราะห์ตอนนี้",
     * synchronous. guidance: the teacher's guidance to the AI (§21.12, at
     * most 500 characters). 422 analysis_no_data, ai_key_missing,
     * ai_key_invalid, validation_failed (errors.guidance); 502 ai_unavailable.
     */
    public function run(Request $request, int $id): JsonResponse
    {
        [$student, $classroom] = $this->studentAndClassroom($request, $id, $request->all());
        ClosedClassrooms::assertOpen($classroom); // §24.6
        $guidance = TeacherGuidance::fromInput($request->all());
        $row = $this->analyses->runNow($student, $classroom, $guidance, $request->user()->id);

        return response()->json(['data' => self::teacherPayload($row)]);
    }

    /** PATCH /api/v1/analyses/{id} {teacher_text?, student_text?} -> {data: teacher payload}. */
    public function update(Request $request, int $id): JsonResponse
    {
        $analysis = self::own($request, $id);
        $input = Validator::make($request->all(), [
            'teacher_text' => ['sometimes', 'required', 'string', 'max:4000'],
            'student_text' => ['sometimes', 'required', 'string', 'max:2000'],
        ], [
            'teacher_text.required' => 'กรอกข้อความสำหรับครู',
            'teacher_text.max' => 'ข้อความสำหรับครูยาวเกิน 4,000 ตัวอักษร',
            'student_text.required' => 'กรอกข้อความสำหรับนักเรียน',
            'student_text.max' => 'ข้อความสำหรับนักเรียนยาวเกิน 2,000 ตัวอักษร',
        ])->validate();
        if ($input === []) {
            throw ValidationException::withMessages(['student_text' => 'ส่ง teacher_text หรือ student_text อย่างน้อยหนึ่งอย่าง']);
        }

        return response()->json(['data' => self::teacherPayload($this->analyses->edit($analysis, $input))]);
    }

    /** POST /api/v1/analyses/{id}/approve -> {data: teacher payload}; 409 analysis_not_ready without a student text. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $analysis = self::own($request, $id);

        return response()->json(['data' => self::teacherPayload($this->analyses->approve($analysis, $request->user()))]);
    }

    /**
     * GET /api/v1/student/analysis -> {data: [{classroom, text, shared_at,
     * next_steps}]}: the signed-in student's shared texts only.
     */
    public function mine(Request $request): JsonResponse
    {
        $student = $request->user();
        $rows = StudentAnalysis::query()
            ->where('student_id', $student->id)
            ->whereNotNull('shared_student_text')
            ->whereHas('classroom.students', fn ($q) => $q->where('users.id', $student->id))
            ->with('classroom:id,name')
            ->orderBy('classroom_id')
            ->get();
        $skills = StudentAnalysisPayload::skillsOf($rows);

        return response()->json(['data' => $rows->map(fn (StudentAnalysis $r) => StudentAnalysisPayload::student($r, $skills))->values()->all()]);
    }

    /**
     * The student (of the teacher's classrooms, else 404) and the classroom
     * from classroom_id (the teacher's, with the student in it, else 422).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: User, 1: Classroom}
     */
    private function studentAndClassroom(Request $request, int $id, array $data): array
    {
        $teacher = $request->user();
        $student = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('school_id', $teacher->school_id)
            ->whereHas('classrooms', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->findOrFail($id);
        Gate::authorize('viewMastery', $student);

        $input = Validator::make($data, [
            'classroom_id' => ['required', 'integer'],
        ], [
            'classroom_id.required' => 'เลือกห้องเรียน',
            'classroom_id.integer' => 'รหัสห้องเรียนไม่ถูกต้อง',
        ])->validate();

        $classroom = Classroom::query()
            ->where('school_id', $teacher->school_id)
            ->where('teacher_id', $teacher->id)
            ->whereHas('students', fn ($q) => $q->where('users.id', $student->id))
            ->find((int) $input['classroom_id']);
        if ($classroom === null) {
            throw ValidationException::withMessages(['classroom_id' => 'นักเรียนคนนี้ไม่ได้อยู่ในห้องเรียนนี้ของครู']);
        }
        Gate::authorize('viewMastery', $classroom);

        return [$student, $classroom];
    }

    /** An analysis of one of the teacher's classrooms, else 404. */
    private static function own(Request $request, int $id): StudentAnalysis
    {
        $teacher = $request->user();
        $analysis = StudentAnalysis::query()
            ->whereHas('classroom', fn ($q) => $q->where('teacher_id', $teacher->id)->where('school_id', $teacher->school_id))
            ->findOrFail($id);
        Gate::authorize('viewMastery', $analysis->classroom()->firstOrFail());

        return $analysis;
    }

    /**
     * @return array<string, mixed>
     */
    private static function teacherPayload(StudentAnalysis $row): array
    {
        return StudentAnalysisPayload::teacher($row, StudentAnalysisPayload::skillsOf([$row]));
    }
}
