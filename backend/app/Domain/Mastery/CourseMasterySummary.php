<?php

namespace App\Domain\Mastery;

use App\Http\Resources\SkillResource;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\LessonPlan;
use App\Models\Mastery;
use App\Models\Skill;
use App\Models\Unit;
use App\Models\User;

/**
 * The mastery roll-up of a course (DESIGN §20.3, §20.7 mastery-summary),
 * computed at read time from `mastery` (nothing is stored):
 *
 *   I(course)   = course_indicators ∪ unit_indicators ∪ the indicators of every plan
 *   I(unit)     = unit_indicators ∪ the indicators of the plans in the unit
 *   I(standard) = the indicators of I(course) whose parent chain reaches the standard
 *
 * axis=standard gives one node per standard (in code order), axis=unit one
 * node per unit (in position order, empty units included: the bar chart
 * shows every planned node). Indicators of the course that fall under no
 * node (no standard above them, or in no unit) form one last node of type
 * `other`, so every planned indicator is somewhere. Every node lists its
 * indicators for the drill-down (tap an axis of the spider chart).
 *
 * forStudent(): one student's values (MasteryRollup::student).
 * forClassroom(): the classroom's values (MasteryRollup::classroom) plus
 * each student's course value, for the teacher only (§20.9: a student
 * never sees a class average). Students whose Google account left the
 * linked course are left out (Classroom::currentStudents).
 */
final class CourseMasterySummary
{
    public const AXIS_STANDARD = 'standard';

    public const AXIS_UNIT = 'unit';

    public const AXES = [self::AXIS_STANDARD, self::AXIS_UNIT];

    public const OTHER_STANDARD_TITLE = 'ไม่มีมาตรฐาน';

    public const OTHER_UNIT_TITLE = 'ไม่อยู่ในหน่วย';

    /** Parent chains are short (sub_indicator → indicator → standard → strand). */
    private const MAX_DEPTH = 8;

    public static function passThreshold(): float
    {
        return (float) config('eduvision.mastery.pass_threshold', 0.5);
    }

    /**
     * @return array<string, mixed>
     */
    public function forStudent(Course $course, string $axis, User $student): array
    {
        $plan = $this->plan($course, $axis);
        $threshold = self::passThreshold();
        $mastery = self::masteryOf([$student->id], $plan['course'])[$student->id] ?? [];

        $node = fn (array $skillIds) => MasteryRollup::student($skillIds, $mastery, $threshold);
        $indicator = function (Skill $skill) use ($mastery, $threshold) {
            $row = $mastery[$skill->id] ?? null;

            return [
                'skill' => SkillResource::indicator($skill),
                'value' => $row === null ? null : $row['value'],
                'n_obs' => $row === null ? 0 : $row['n_obs'],
                'level' => $row === null ? null : MasteryCalculator::level($row['value'], $row['n_obs']),
                'passed' => $row === null ? null : MasteryRollup::passes($row['value'], $threshold),
            ];
        };

        return self::header($course, $axis, 'student', $threshold) + [
            'student_id' => $student->id,
            'summary' => $node($plan['course']),
            'nodes' => self::nodes($plan, $node, $indicator),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forClassroom(Course $course, string $axis, Classroom $classroom): array
    {
        $plan = $this->plan($course, $axis);
        $threshold = self::passThreshold();
        $students = $classroom->currentStudents()->get(['users.id', 'users.name']);
        $byStudent = [];
        foreach ($students as $student) {
            $byStudent[$student->id] = [];
        }
        foreach (self::masteryOf($students->modelKeys(), $plan['course']) as $studentId => $rows) {
            $byStudent[$studentId] = $rows;
        }

        $node = fn (array $skillIds) => MasteryRollup::classroom($skillIds, $byStudent, $threshold);
        $indicator = function (Skill $skill) use ($byStudent, $threshold) {
            $values = [];
            foreach ($byStudent as $rows) {
                if (($rows[$skill->id]['n_obs'] ?? 0) >= 1) {
                    $values[] = $rows[$skill->id]['value'];
                }
            }

            return [
                'skill' => SkillResource::indicator($skill),
                'value' => $values === [] ? null : MasteryCalculator::round3(array_sum($values) / count($values)),
                'assessed_students' => count($values),
                'passed_students' => count(array_filter($values, fn (float $v) => MasteryRollup::passes($v, $threshold))),
            ];
        };

        return self::header($course, $axis, 'classroom', $threshold) + [
            'classroom_id' => $classroom->id,
            'summary' => $node($plan['course']),
            'nodes' => self::nodes($plan, $node, $indicator),
            'students' => $students->map(fn (User $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'student_number' => (int) $s->pivot->student_number,
                ...MasteryRollup::student($plan['course'], $byStudent[$s->id], $threshold),
            ])->values()->all(),
        ];
    }

    /**
     * The planned indicators of the course and its nodes on one axis.
     *
     * @return array{course: list<int>, nodes: list<array{type: string, id: int|null, code: string|null, title: string, position: int|null, skill_ids: list<int>}>, skills: array<int, Skill>}
     */
    public function plan(Course $course, string $axis): array
    {
        $course->load(['indicators', 'units.indicators', 'lessonPlans.indicators']);

        /** @var array<int, Skill> $skills */
        $skills = [];
        $add = function (iterable $indicators) use (&$skills): array {
            $ids = [];
            foreach ($indicators as $skill) {
                $skills[$skill->id] = $skill;
                $ids[] = $skill->id;
            }

            return $ids;
        };

        $all = $add($course->indicators);
        $units = [];
        foreach ($course->units as $unit) {
            $units[$unit->id] = $add($unit->indicators);
        }
        foreach ($course->lessonPlans as $plan) {
            /** @var LessonPlan $plan */
            $ids = $add($plan->indicators);
            if ($plan->unit_id !== null && isset($units[$plan->unit_id])) {
                $units[$plan->unit_id] = [...$units[$plan->unit_id], ...$ids];
            }
            $all = [...$all, ...$ids];
        }
        foreach ($units as $ids) {
            $all = [...$all, ...$ids];
        }
        $all = self::sortIds(array_values(array_unique($all)), $skills);

        $nodes = [];
        $placed = [];
        if ($axis === self::AXIS_UNIT) {
            foreach ($course->units as $unit) {
                /** @var Unit $unit */
                $ids = self::sortIds(array_values(array_unique($units[$unit->id])), $skills);
                $placed = [...$placed, ...$ids];
                $nodes[] = ['type' => 'unit', 'id' => $unit->id, 'code' => null, 'title' => $unit->title, 'position' => $unit->position, 'skill_ids' => $ids];
            }
            $otherTitle = self::OTHER_UNIT_TITLE;
        } else {
            $standards = $this->standardsOf(array_map(fn (int $id) => $skills[$id], $all));
            $groups = [];
            foreach ($all as $id) {
                $standard = $standards[$id] ?? null;
                if ($standard !== null) {
                    $groups[$standard->id] ??= ['standard' => $standard, 'ids' => []];
                    $groups[$standard->id]['ids'][] = $id;
                }
            }
            uasort($groups, fn (array $a, array $b) => strnatcmp($a['standard']->code, $b['standard']->code) ?: $a['standard']->id <=> $b['standard']->id);
            foreach ($groups as $group) {
                $placed = [...$placed, ...$group['ids']];
                $nodes[] = ['type' => 'standard', 'id' => $group['standard']->id, 'code' => $group['standard']->code, 'title' => $group['standard']->name, 'position' => null, 'skill_ids' => $group['ids']];
            }
            $otherTitle = self::OTHER_STANDARD_TITLE;
        }

        $rest = array_values(array_diff($all, $placed));
        if ($rest !== []) {
            $nodes[] = ['type' => 'other', 'id' => null, 'code' => null, 'title' => $otherTitle, 'position' => null, 'skill_ids' => $rest];
        }

        return ['course' => $all, 'nodes' => $nodes, 'skills' => $skills];
    }

    /**
     * The standard above each indicator (walking parent_id), keyed by the
     * indicator's id. Indicators with no standard above them are absent.
     * Also groups the heatmap columns (§20.4 chart 3).
     *
     * @param  list<Skill>  $indicators
     * @return array<int, Skill>
     */
    public function standardsOf(array $indicators): array
    {
        /** @var array<int, Skill> $known */
        $known = [];
        foreach ($indicators as $skill) {
            $known[$skill->id] = $skill;
        }
        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $missing = [];
            foreach ($known as $skill) {
                if ($skill->parent_id !== null && ! isset($known[$skill->parent_id])) {
                    $missing[$skill->parent_id] = true;
                }
            }
            if ($missing === []) {
                break;
            }
            foreach (Skill::query()->whereIn('id', array_keys($missing))->get() as $parent) {
                $known[$parent->id] = $parent;
            }
        }

        $out = [];
        foreach ($indicators as $skill) {
            $current = $skill->parent_id === null ? null : ($known[$skill->parent_id] ?? null);
            for ($depth = 0; $current !== null && $depth < self::MAX_DEPTH; $depth++) {
                if ($current->level === Skill::LEVEL_STANDARD) {
                    $out[$skill->id] = $current;
                    break;
                }
                $current = $current->parent_id === null ? null : ($known[$current->parent_id] ?? null);
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $studentIds
     * @param  list<int>  $skillIds
     * @return array<int, array<int, array{value: float, n_obs: int}>> student id => skill id => row
     */
    private static function masteryOf(array $studentIds, array $skillIds): array
    {
        if ($studentIds === [] || $skillIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($studentIds, 500) as $chunk) {
            $rows = Mastery::query()
                ->whereIn('student_id', $chunk)
                ->whereIn('skill_id', $skillIds)
                ->get(['student_id', 'skill_id', 'value', 'n_obs']);
            foreach ($rows as $row) {
                $out[$row->student_id][$row->skill_id] = ['value' => (float) $row->value, 'n_obs' => (int) $row->n_obs];
            }
        }

        return $out;
    }

    /**
     * @param  array{course: list<int>, nodes: list<array{type: string, id: int|null, code: string|null, title: string, position: int|null, skill_ids: list<int>}>, skills: array<int, Skill>}  $plan
     * @param  callable(list<int>): array<string, mixed>  $node
     * @param  callable(Skill): array<string, mixed>  $indicator
     * @return list<array<string, mixed>>
     */
    private static function nodes(array $plan, callable $node, callable $indicator): array
    {
        return array_map(fn (array $n) => [
            'type' => $n['type'],
            'id' => $n['id'],
            'code' => $n['code'],
            'title' => $n['title'],
            'position' => $n['position'],
            ...$node($n['skill_ids']),
            'indicators' => array_map(fn (int $id) => $indicator($plan['skills'][$id]), $n['skill_ids']),
        ], $plan['nodes']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function header(Course $course, string $axis, string $scope, float $threshold): array
    {
        return [
            'course' => ['id' => $course->id, 'code' => $course->code, 'name' => $course->name, 'subject_id' => $course->subject_id, 'grade_level' => $course->grade_level],
            'axis' => $axis,
            'scope' => $scope,
            'pass_threshold' => $threshold,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, Skill>  $skills
     * @return list<int>
     */
    private static function sortIds(array $ids, array $skills): array
    {
        usort($ids, fn (int $a, int $b) => strnatcmp($skills[$a]->code, $skills[$b]->code) ?: $a <=> $b);

        return $ids;
    }
}
