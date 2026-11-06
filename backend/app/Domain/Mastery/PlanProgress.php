<?php

namespace App\Domain\Mastery;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\LessonPlan;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;

/**
 * Chart (5) of DESIGN §20.4: how far a course has come against its plan,
 * one stacked bar per unit (the unit axis of CourseMasterySummary::plan(),
 * so the last node `other` holds what is in no unit).
 *
 *   planned  = I(unit) (§20.3)
 *   taught   = planned indicators of a lesson plan with taught_on set
 *   assessed = planned indicators of a question of a published assignment
 *              of the course (a submission is published; in the classroom
 *              when one is given)
 *
 * The stack is disjoint: assessed, taught_not_assessed, not_taught (sums
 * to planned; an indicator assessed before its plan was marked taught
 * counts as assessed).
 */
final class PlanProgress
{
    public function __construct(private readonly CourseMasterySummary $summary) {}

    /**
     * @return array<string, mixed>
     */
    public function forCourse(Course $course, ?Classroom $classroom): array
    {
        $plan = $this->summary->plan($course, CourseMasterySummary::AXIS_UNIT);

        $taught = [];
        $plansByUnit = [];
        foreach ($course->lessonPlans as $lessonPlan) {
            /** @var LessonPlan $lessonPlan */
            $key = $lessonPlan->unit_id ?? 0;
            $plansByUnit[$key] ??= ['total' => 0, 'taught' => 0];
            $plansByUnit[$key]['total']++;
            if ($lessonPlan->taught_on !== null) {
                $plansByUnit[$key]['taught']++;
                foreach ($lessonPlan->indicators as $skill) {
                    $taught[$skill->id] = true;
                }
            }
        }

        $assessed = array_fill_keys($this->assessedSkillIds($course, $classroom), true);
        $unitIds = array_filter(array_map(fn (array $n) => $n['type'] === 'unit' ? $n['id'] : null, $plan['nodes']));

        $counts = function (array $ids) use ($taught, $assessed): array {
            $ids = array_values(array_unique($ids));
            $a = count(array_filter($ids, fn (int $id) => isset($assessed[$id])));
            $t = count(array_filter($ids, fn (int $id) => isset($taught[$id])));
            $tOnly = count(array_filter($ids, fn (int $id) => isset($taught[$id]) && ! isset($assessed[$id])));

            return [
                'planned' => count($ids),
                'taught' => $t,
                'assessed' => $a,
                'taught_not_assessed' => $tOnly,
                'not_taught' => count($ids) - $a - $tOnly,
            ];
        };

        $units = [];
        foreach ($plan['nodes'] as $node) {
            $plans = $node['type'] === 'unit'
                ? ($plansByUnit[$node['id']] ?? ['total' => 0, 'taught' => 0])
                : self::plansOutside($plansByUnit, $unitIds);
            $units[] = [
                'type' => $node['type'],
                'id' => $node['id'],
                'title' => $node['title'],
                'position' => $node['position'],
                ...$counts($node['skill_ids']),
                'plans_total' => $plans['total'],
                'plans_taught' => $plans['taught'],
            ];
        }

        $allPlans = ['total' => 0, 'taught' => 0];
        foreach ($plansByUnit as $p) {
            $allPlans['total'] += $p['total'];
            $allPlans['taught'] += $p['taught'];
        }

        return [
            'course' => ['id' => $course->id, 'code' => $course->code, 'name' => $course->name],
            'classroom_id' => $classroom?->id,
            'summary' => [...$counts($plan['course']), 'plans_total' => $allPlans['total'], 'plans_taught' => $allPlans['taught']],
            'units' => $units,
        ];
    }

    /**
     * Skills of the questions of the course's assignments with at least one published submission.
     *
     * @return list<int>
     */
    private function assessedSkillIds(Course $course, ?Classroom $classroom): array
    {
        $assignments = Assignment::query()
            ->select('id')
            ->where('course_id', $course->id)
            ->when($classroom !== null, fn ($q) => $q->where('classroom_id', $classroom->id))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('submissions')
                ->whereColumn('submissions.assignment_id', 'assignments.id')
                ->where('submissions.status', Submission::STATUS_PUBLISHED));

        return DB::table('question_skill')
            ->join('questions', 'questions.id', '=', 'question_skill.question_id')
            ->whereIn('questions.assignment_id', $assignments)
            ->distinct()
            ->pluck('question_skill.skill_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Lesson plans in no unit, or in a unit that is not on the axis.
     *
     * @param  array<int, array{total: int, taught: int}>  $plansByUnit
     * @param  array<int, int>  $unitIds
     * @return array{total: int, taught: int}
     */
    private static function plansOutside(array $plansByUnit, array $unitIds): array
    {
        $out = ['total' => 0, 'taught' => 0];
        foreach ($plansByUnit as $unitId => $p) {
            if ($unitId === 0 || ! in_array($unitId, $unitIds, true)) {
                $out['total'] += $p['total'];
                $out['taught'] += $p['taught'];
            }
        }

        return $out;
    }
}
