<?php

namespace App\Domain\Courses;

use Illuminate\Support\Facades\DB;

/**
 * The indicators a subject teacher sees of a student or a classroom
 * (DESIGN §24.8): the course's planned indicators (course_indicators ∪
 * unit_indicators ∪ lesson_plan_indicators) and those of the questions of
 * the course's assignments. Mastery itself is per (student, indicator) from
 * every source, so another course assessing the same indicator shows in the
 * value, never its work or scores.
 */
final class CourseIndicatorIds
{
    /**
     * @return list<int>
     */
    public static function of(int $courseId): array
    {
        $ids = DB::table('course_indicators')->where('course_id', $courseId)->pluck('skill_id')
            ->merge(DB::table('unit_indicators')
                ->whereIn('unit_id', DB::table('units')->select('id')->where('course_id', $courseId))
                ->pluck('skill_id'))
            ->merge(DB::table('lesson_plan_indicators')
                ->whereIn('lesson_plan_id', DB::table('lesson_plans')->select('id')->where('course_id', $courseId))
                ->pluck('skill_id'))
            ->merge(DB::table('question_skill')
                ->whereIn('question_id', DB::table('questions')->select('id')
                    ->whereIn('assignment_id', DB::table('assignments')->select('id')->where('course_id', $courseId)))
                ->pluck('skill_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }
}
