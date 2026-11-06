<?php

namespace App\Domain\Mastery;

use App\Http\Resources\SkillResource;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Mastery;
use App\Models\Skill;

/**
 * Chart (2) of DESIGN §20.4: the share of a classroom's students who pass
 * each indicator ("ผ่าน" = mastery ≥ MASTERY_PASS_THRESHOLD, §20.3), out of
 * the students assessed on it (n_obs ≥ 1), with that n.
 *
 * With a course: every indicator planned in it (I(course) of §20.3, the
 * unassessed ones with pass_rate null). Without: every skill some student
 * of the classroom has mastery for. Students whose Google account left the
 * linked course are not counted (Classroom::currentStudents).
 */
final class IndicatorPassRate
{
    public function __construct(private readonly CourseMasterySummary $summary) {}

    /**
     * @return array<string, mixed>
     */
    public function forClassroom(Classroom $classroom, ?Course $course): array
    {
        $threshold = CourseMasterySummary::passThreshold();
        $studentIds = $classroom->currentStudents()->pluck('users.id')->all();

        $rows = Mastery::query()
            ->whereIn('student_id', $studentIds === [] ? [0] : $studentIds)
            ->where('n_obs', '>=', 1)
            ->get(['student_id', 'skill_id', 'value']);

        if ($course !== null) {
            $plan = $this->summary->plan($course, CourseMasterySummary::AXIS_STANDARD);
            $skills = array_map(fn (int $id) => $plan['skills'][$id], $plan['course']);
        } else {
            $skills = Skill::query()->whereIn('id', $rows->pluck('skill_id')->unique()->all())->get()->all();
            usort($skills, fn (Skill $a, Skill $b) => strnatcmp($a->code, $b->code) ?: $a->id <=> $b->id);
        }

        $bySkill = $rows->groupBy('skill_id');

        return [
            'classroom_id' => $classroom->id,
            'course_id' => $course?->id,
            'pass_threshold' => $threshold,
            'student_count' => count($studentIds),
            'indicators' => array_map(function (Skill $skill) use ($bySkill, $threshold) {
                $values = ($bySkill[$skill->id] ?? collect())->map(fn (Mastery $m) => (float) $m->value)->all();
                $passed = count(array_filter($values, fn (float $v) => MasteryRollup::passes($v, $threshold)));

                return [
                    'skill' => SkillResource::indicator($skill),
                    'assessed_students' => count($values),
                    'passed_students' => $passed,
                    'pass_rate' => $values === [] ? null : MasteryCalculator::round3($passed / count($values)),
                ];
            }, $skills),
        ];
    }
}
