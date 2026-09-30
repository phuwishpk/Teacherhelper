<?php

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\LessonPlan;
use App\Models\Skill;
use App\Models\Unit;

/**
 * The API form of courses, units and lesson plans (DESIGN §20.7).
 *
 * course: {id, code, name, subject_id, subject: {id, code, name},
 *  grade_level, semester (0 = the whole year), academic_year (พ.ศ.),
 *  hours, description, classroom_ids[], classrooms: [{id, name}],
 *  indicator_count, unit_count, lesson_plan_count, assignment_count,
 *  created_at, updated_at}
 * detail adds indicators: [indicator], units: [unit], lesson_plans: [plan].
 *
 * unit: {id, course_id, position, title, hours, description, indicators}
 * plan: {id, course_id, unit_id, position, title, hours, objectives,
 *  content, activities, assessment, taught_on (YYYY-MM-DD, Asia/Bangkok
 *  date as the teacher picked it), indicators}
 * indicator: SkillResource::indicator (with level and source_label).
 */
final class CourseResource
{
    public static function loadSummary(Course $course): Course
    {
        return $course->loadMissing(['subject', 'classrooms'])
            ->loadCount(['indicators', 'units', 'lessonPlans', 'assignments']);
    }

    public static function loadDetail(Course $course): Course
    {
        return self::loadSummary($course)->load(['indicators', 'units.indicators', 'lessonPlans.indicators']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Course $course): array
    {
        $classrooms = $course->classrooms->sortBy('name')->values();

        return [
            'id' => $course->id,
            'code' => $course->code,
            'name' => $course->name,
            'subject_id' => $course->subject_id,
            'subject' => $course->subject === null ? null : [
                'id' => $course->subject->id,
                'code' => $course->subject->code,
                'name' => $course->subject->name,
            ],
            'grade_level' => $course->grade_level,
            'semester' => $course->semester,
            'academic_year' => $course->academic_year,
            'hours' => $course->hours,
            'description' => $course->description,
            'classroom_ids' => $classrooms->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'classrooms' => $classrooms->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'indicator_count' => (int) ($course->indicators_count ?? 0),
            'unit_count' => (int) ($course->units_count ?? 0),
            'lesson_plan_count' => (int) ($course->lesson_plans_count ?? 0),
            'assignment_count' => (int) ($course->assignments_count ?? 0),
            'created_at' => $course->created_at?->toIso8601String(),
            'updated_at' => $course->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Course $course): array
    {
        return self::summary($course) + [
            'indicators' => self::indicators($course->indicators),
            'units' => $course->units->map(fn (Unit $u) => self::unit($u))->all(),
            'lesson_plans' => $course->lessonPlans->map(fn (LessonPlan $p) => self::plan($p))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function unit(Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'course_id' => $unit->course_id,
            'position' => $unit->position,
            'title' => $unit->title,
            'hours' => $unit->hours,
            'description' => $unit->description,
            'indicators' => self::indicators($unit->indicators),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function plan(LessonPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'course_id' => $plan->course_id,
            'unit_id' => $plan->unit_id,
            'position' => $plan->position,
            'title' => $plan->title,
            'hours' => $plan->hours,
            'objectives' => $plan->objectives,
            'content' => $plan->content,
            'activities' => $plan->activities,
            'assessment' => $plan->assessment,
            'taught_on' => $plan->taught_on?->format('Y-m-d'),
            'indicators' => self::indicators($plan->indicators),
        ];
    }

    /**
     * @param  iterable<Skill>  $skills
     * @return list<array<string, mixed>>
     */
    private static function indicators(iterable $skills): array
    {
        $list = [];
        foreach ($skills as $skill) {
            $list[] = SkillResource::indicator($skill);
        }
        usort($list, fn (array $a, array $b) => strnatcmp($a['code'], $b['code']) ?: $a['id'] <=> $b['id']);

        return $list;
    }
}
