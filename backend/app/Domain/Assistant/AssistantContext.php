<?php

namespace App\Domain\Assistant;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Courses\IndicatorScope;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Question;
use App\Models\User;

/**
 * "YOUR DATA" of an assistant message (DESIGN §37.6): what Gemini needs to
 * propose questions, and nothing about students.
 *
 * - The teacher's open classrooms (id, name, grade) with the courses of
 *   theirs bound to each, and the assignments they manage that are not
 *   closed (newest MAX_ASSIGNMENTS: id, title, classroom, kind, status,
 *   question count).
 * - For the likely targets (the latest card's assignment and the one whose
 *   title best matches the message, at most two): the existing questions
 *   (type and a short prompt, to avoid repeating them) and the indicators
 *   the questions may use (IndicatorScope).
 */
final class AssistantContext
{
    public const MAX_ASSIGNMENTS = 60;

    public const MAX_CLASSROOMS = 40;

    /** Indicators listed per target. */
    public const MAX_INDICATORS = 80;

    /** Share of an assignment title's trigrams the message must contain. */
    private const TITLE_MATCH = 0.6;

    public static function build(User $teacher, string $message, ?int $previousTarget): string
    {
        $classrooms = ClassroomAccess::classrooms($teacher)->open()->orderBy('grade_level')->orderBy('name')
            ->limit(self::MAX_CLASSROOMS)->get(['classrooms.id', 'classrooms.name', 'classrooms.grade_level']);
        $courses = Course::query()->where('created_by', $teacher->id)
            ->whereHas('classrooms', fn ($q) => $q->whereIn('classrooms.id', $classrooms->pluck('id')))
            ->with('classrooms:classrooms.id')->get(['id', 'code', 'name']);
        $assignments = ClassroomAccess::managedAssignments($teacher)
            ->where('assignments.status', '!=', Assignment::STATUS_CLOSED)
            ->with('classroom:id,name')->withCount('questions')
            ->latest('assignments.updated_at')->limit(self::MAX_ASSIGNMENTS)->get();

        $lines = ['# YOUR DATA (this teacher only; names and ids, no students)', '', 'Classrooms (id | name | grade | courses of this teacher bound to it):'];
        foreach ($classrooms as $c) {
            $bound = $courses->filter(fn (Course $course) => $course->classrooms->contains('id', $c->id))
                ->map(fn (Course $course) => "{$course->id} {$course->code} {$course->name}")->implode('; ');
            $lines[] = "- {$c->id} | {$c->name} | ".self::grade($c).' | '.($bound === '' ? 'no course yet' : "courses: {$bound}");
        }
        if ($classrooms->isEmpty()) {
            $lines[] = '(none)';
        }
        $lines[] = '';
        $lines[] = 'Assignments not closed, newest first (id | title | classroom | kind | status | questions):';
        foreach ($assignments as $a) {
            $lines[] = "- {$a->id} | {$a->title} | {$a->classroom?->name} | ".AssistantPlans::kindOf($a)." | {$a->status} | {$a->questions_count}";
        }
        if ($assignments->isEmpty()) {
            $lines[] = '(none)';
        }

        foreach (self::targets($assignments->all(), $message, $previousTarget) as $target) {
            array_push($lines, '', ...self::details($target));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<Assignment>  $assignments
     * @return list<Assignment>
     */
    private static function targets(array $assignments, string $message, ?int $previousTarget): array
    {
        $targets = [];
        foreach ($assignments as $a) {
            if ($a->id === $previousTarget) {
                $targets[] = $a;
            }
        }
        $wanted = AssistantKnowledge::grams($message);
        $best = null;
        $bestScore = self::TITLE_MATCH;
        foreach ($assignments as $a) {
            $title = AssistantKnowledge::grams($a->title);
            if (count($title) < 3 || $a->id === $previousTarget) {
                continue;
            }
            $score = count(array_intersect_key($title, $wanted)) / count($title);
            if ($score >= $bestScore) {
                [$best, $bestScore] = [$a, $score];
            }
        }
        if ($best !== null) {
            $targets[] = $best;
        }

        return $targets;
    }

    /** @return list<string> */
    private static function details(Assignment $a): array
    {
        $lines = ["## Assignment {$a->id} \"{$a->title}\" (".AssistantPlans::kindOf($a).')', 'Existing questions:'];
        $questions = Question::query()->where('assignment_id', $a->id)->orderBy('position')->limit(100)->get(['position', 'type', 'prompt_text']);
        foreach ($questions as $q) {
            $lines[] = "{$q->position}. {$q->type}: ".mb_substr(preg_replace('/\s+/u', ' ', $q->prompt_text) ?? '', 0, 80);
        }
        if ($questions->isEmpty()) {
            $lines[] = '(none)';
        }
        $scope = IndicatorScope::of($a);
        if ($scope === null || $scope->isEmpty()) {
            $lines[] = 'Indicators: none to pick from (no lesson plan): leave indicator_codes empty.';
        } else {
            $lines[] = 'Indicators the questions may use (code | name):';
            foreach (array_slice($scope->indicators, 0, self::MAX_INDICATORS) as $skill) {
                $lines[] = "- {$skill->code} | {$skill->name}";
            }
        }

        return $lines;
    }

    private static function grade(Classroom $c): string
    {
        $level = (int) $c->grade_level;

        return $level <= 6 ? "ป.{$level}" : 'ม.'.($level - 6);
    }
}
