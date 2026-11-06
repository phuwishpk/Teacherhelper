<?php

namespace App\Domain\Courses;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\LessonPlan;
use App\Models\Skill;

/**
 * The indicators Gemini may suggest for the questions of an assignment
 * (DESIGN §20.3, §22.13), and what the prompt says about where they come
 * from:
 *
 * - linked to a lesson plan (homework or exam): the plan's indicators;
 * - an exam not linked to a plan: the course's planned indicators
 *   (course_indicators ∪ unit_indicators ∪ every plan's, the I(รายวิชา) of
 *   §20.3), because an exam usually covers several plans. Such an exam is
 *   never refused with lesson_plan_required;
 * - homework without a plan: none (null, lesson_plan_required).
 *
 * The indicators are sorted by code (natural order) and distinct.
 */
final class IndicatorScope
{
    public const SOURCE_LESSON_PLAN = 'lesson_plan';

    public const SOURCE_COURSE = 'course';

    /**
     * @param  list<Skill>  $indicators
     */
    private function __construct(
        public readonly string $source,
        public readonly ?LessonPlan $plan,
        public readonly ?Course $course,
        public readonly array $indicators,
    ) {}

    public static function of(Assignment $assignment): ?self
    {
        if ($assignment->lesson_plan_id !== null) {
            $plan = LessonPlan::query()->with(['indicators', 'course.subject'])->find($assignment->lesson_plan_id);
            if ($plan !== null) {
                return new self(self::SOURCE_LESSON_PLAN, $plan, $plan->course, self::sorted($plan->indicators->all()));
            }
        }
        if (! $assignment->isExam() || $assignment->course_id === null) {
            return null;
        }
        $course = Course::query()->with(['subject', 'indicators', 'units.indicators', 'lessonPlans.indicators'])->find($assignment->course_id);
        if ($course === null) {
            return null;
        }
        $skills = $course->indicators->all();
        foreach ($course->units as $unit) {
            array_push($skills, ...$unit->indicators->all());
        }
        foreach ($course->lessonPlans as $plan) {
            array_push($skills, ...$plan->indicators->all());
        }

        return new self(self::SOURCE_COURSE, null, $course, self::sorted($skills));
    }

    public function isEmpty(): bool
    {
        return $this->indicators === [];
    }

    /** @return list<int> */
    public function skillIds(): array
    {
        return array_map(fn (Skill $s) => $s->id, $this->indicators);
    }

    /** The "LESSON PLAN" line of the prompt: the plan's title, or the whole course the exam covers. */
    public function promptTitle(Assignment $assignment): string
    {
        if ($this->plan !== null) {
            return trim($this->plan->title);
        }
        $course = trim(($this->course?->code ?? '').' '.($this->course?->name ?? ''));

        return 'ข้อสอบ "'.trim($assignment->title).'" ครอบคลุมทุกแผนของรายวิชา '.$course;
    }

    /** The plan's objectives, or the course description for a course-wide exam. */
    public function promptObjectives(): string
    {
        return trim((string) ($this->plan !== null ? $this->plan->objectives : $this->course?->description));
    }

    /**
     * @param  list<Skill>  $skills
     * @return list<Skill>
     */
    private static function sorted(array $skills): array
    {
        $unique = [];
        foreach ($skills as $skill) {
            $unique[$skill->id] ??= $skill;
        }
        $out = array_values($unique);
        usort($out, fn (Skill $a, Skill $b) => strnatcmp($a->code, $b->code) ?: $a->id <=> $b->id);

        return $out;
    }
}
