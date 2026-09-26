<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mastery\MasteryCalculator;
use App\Http\Controllers\Controller;
use App\Http\Resources\SkillResource;
use App\Models\Classroom;
use App\Models\Mastery;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Mastery for teachers (DESIGN §9.6, §14.3): the student × skill heatmap of
 * a classroom and one student's mastery with their three weakest skills.
 */
class MasteryController extends Controller
{
    /**
     * GET /api/v1/classrooms/{id}/mastery -> {data: {classroom_id, skills:
     * [{id, code, name, subject_id, grade_level}], students: [{id, name,
     * student_number}], cells: [{student_id, skill_id, value, n_obs, level}]}}
     */
    public function classroom(Request $request, int $id): JsonResponse
    {
        $teacher = $request->user();
        $classroom = Classroom::query()
            ->where('school_id', $teacher->school_id)
            ->where('teacher_id', $teacher->id)
            ->findOrFail($id);
        Gate::authorize('viewMastery', $classroom);

        $students = $classroom->students()->get(['users.id', 'users.name']);
        $cells = Mastery::query()->whereIn('student_id', $students->modelKeys())->get();
        $skills = Skill::query()->whereIn('id', $cells->pluck('skill_id')->unique())->orderBy('code')->orderBy('id')->get();

        return response()->json(['data' => [
            'classroom_id' => $classroom->id,
            'skills' => $skills->map(fn (Skill $s) => SkillResource::summary($s))->all(),
            'students' => $students->map(fn (User $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'student_number' => (int) $s->pivot->student_number,
            ])->all(),
            'cells' => $cells->map(fn (Mastery $m) => [
                'student_id' => $m->student_id,
                'skill_id' => $m->skill_id,
                'value' => (float) $m->value,
                'n_obs' => (int) $m->n_obs,
                'level' => MasteryCalculator::level((float) $m->value, (int) $m->n_obs),
            ])->all(),
        ]]);
    }

    /**
     * GET /api/v1/students/{id}/mastery -> the same payload as
     * GET /student/mastery for a student of the teacher's classrooms
     * (others are 404).
     */
    public function student(Request $request, int $id): JsonResponse
    {
        $teacher = $request->user();
        $student = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('school_id', $teacher->school_id)
            ->whereHas('classrooms', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->findOrFail($id);
        Gate::authorize('viewMastery', $student);

        return response()->json(['student' => ['id' => $student->id, 'name' => $student->name]] + StudentMasteryController::payload($student->id));
    }
}
