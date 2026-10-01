<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mastery\CourseMasterySummary;
use App\Domain\Mastery\MasteryCalculator;
use App\Http\Controllers\Controller;
use App\Http\Resources\SkillResource;
use App\Models\Course;
use App\Models\Mastery;
use App\Models\Skill;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Mastery for teachers (DESIGN §9.6, §14.3): the student × skill heatmap of
 * a classroom (with the course/unit filters and standard groups of §20.4
 * chart 3) and one student's mastery with their three weakest skills.
 */
class MasteryController extends Controller
{
    /**
     * GET /api/v1/classrooms/{id}/mastery?course_id=&unit_id= -> {data:
     * {classroom_id, course_id, unit_id, skills: [{id, code, name,
     * subject_id, grade_level}], groups: [{standard: {id, code, name}|null,
     * skill_ids}], students: [{id, name, student_number}], cells:
     * [{student_id, skill_id, value, n_obs, level}]}}
     *
     * Without filters: every skill a student has mastery for (§14.3), for the
     * homeroom teacher only; a subject teacher must give course_id (§24.8).
     * With course_id (a course bound to the classroom; the teacher's own for
     * a subject teacher): every
     * indicator planned in it, assessed or not; with unit_id too: those of
     * the unit (§20.4 chart 3). Columns are grouped by the standard above
     * them (standards in code order, the ungrouped last).
     */
    public function classroom(Request $request, int $id, CourseMasterySummary $summary): JsonResponse
    {
        $classroom = ChartController::ownClassroom($request, $id);
        $course = ChartController::courseOf($request, $classroom);
        $unitId = self::unitOf($request, $course);

        $students = $classroom->students()->get(['users.id', 'users.name']);
        $cells = Mastery::query()->whereIn('student_id', $students->modelKeys());

        if ($course !== null) {
            $plan = $summary->plan($course, $unitId === null ? CourseMasterySummary::AXIS_STANDARD : CourseMasterySummary::AXIS_UNIT);
            $ids = $unitId === null
                ? $plan['course']
                : (collect($plan['nodes'])->first(fn (array $n) => $n['type'] === 'unit' && $n['id'] === $unitId)['skill_ids'] ?? []);
            $skills = array_map(fn (int $sid) => $plan['skills'][$sid], $ids);
            $cells = $cells->whereIn('skill_id', $ids)->get();
        } else {
            $cells = $cells->get();
            $skills = Skill::query()->whereIn('id', $cells->pluck('skill_id')->unique())->orderBy('code')->orderBy('id')->get()->all();
        }

        [$skills, $groups] = self::grouped($skills, $summary);

        return response()->json(['data' => [
            'classroom_id' => $classroom->id,
            'course_id' => $course?->id,
            'unit_id' => $unitId,
            'skills' => array_map(fn (Skill $s) => SkillResource::summary($s), $skills),
            'groups' => $groups,
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
            ])->values()->all(),
        ]]);
    }

    /** ?unit_id= needs course_id and must be a unit of that course (422 on the field otherwise). */
    private static function unitOf(Request $request, ?Course $course): ?int
    {
        $input = Validator::make($request->query(), [
            'unit_id' => ['sometimes', 'nullable', 'integer'],
        ], ['unit_id.integer' => 'รหัสหน่วยไม่ถูกต้อง'])->validate();
        if (($input['unit_id'] ?? null) === null) {
            return null;
        }
        if ($course === null) {
            throw ValidationException::withMessages(['course_id' => 'เลือกรายวิชาก่อนเลือกหน่วย']);
        }
        $unitId = (int) $input['unit_id'];
        if (! Unit::query()->where('course_id', $course->id)->whereKey($unitId)->exists()) {
            throw ValidationException::withMessages(['unit_id' => 'หน่วยนี้ไม่ได้อยู่ในรายวิชาที่เลือก']);
        }

        return $unitId;
    }

    /**
     * The skills reordered by the standard above them, and the groups.
     *
     * @param  list<Skill>  $skills
     * @return array{0: list<Skill>, 1: list<array{standard: array{id: int, code: string, name: string}|null, skill_ids: list<int>}>}
     */
    private static function grouped(array $skills, CourseMasterySummary $summary): array
    {
        $standards = $summary->standardsOf($skills);
        $groups = [];
        $rest = [];
        foreach ($skills as $skill) {
            $standard = $standards[$skill->id] ?? null;
            if ($standard === null) {
                $rest[] = $skill;

                continue;
            }
            $groups[$standard->id] ??= ['standard' => $standard, 'skills' => []];
            $groups[$standard->id]['skills'][] = $skill;
        }
        uasort($groups, fn (array $a, array $b) => strnatcmp($a['standard']->code, $b['standard']->code) ?: $a['standard']->id <=> $b['standard']->id);

        $ordered = [];
        $out = [];
        foreach ($groups as $group) {
            $ordered = [...$ordered, ...$group['skills']];
            $out[] = [
                'standard' => ['id' => $group['standard']->id, 'code' => $group['standard']->code, 'name' => $group['standard']->name],
                'skill_ids' => array_map(fn (Skill $s) => $s->id, $group['skills']),
            ];
        }
        if ($rest !== []) {
            $ordered = [...$ordered, ...$rest];
            $out[] = ['standard' => null, 'skill_ids' => array_map(fn (Skill $s) => $s->id, $rest)];
        }

        return [$ordered, $out];
    }

    /**
     * GET /api/v1/students/{id}/mastery -> the same payload as
     * GET /student/mastery for a student of the teacher's classrooms
     * (others are 404); a subject teacher names an own course (?course_id=)
     * and sees its indicators only (DESIGN §24.8).
     */
    public function student(Request $request, int $id): JsonResponse
    {
        [$student, $allowed] = ChartController::visibleStudent($request, $id);

        return response()->json(['student' => ['id' => $student->id, 'name' => $student->name]] + StudentMasteryController::payload($student->id, $allowed));
    }
}
