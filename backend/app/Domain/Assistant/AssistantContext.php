<?php

namespace App\Domain\Assistant;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Courses\IndicatorScope;
use App\Domain\Gradebook\GradebookSettings;
use App\Domain\Skills\TeacherIndicators;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Question;
use App\Models\Skill;
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
 * - When the message is about indicators (or the latest card was an
 *   indicator card): the standards and indicators of the subjects and
 *   grades the teacher's courses teach, those in "ตัวชี้วัดของฉัน" starred.
 */
final class AssistantContext
{
    public const MAX_ASSIGNMENTS = 60;

    public const MAX_CLASSROOMS = 40;

    /** Indicators listed per target. */
    public const MAX_INDICATORS = 80;

    /** Lines of indicators per subject and grade, and how many of those. */
    public const MAX_SKILL_LINES = 150;

    public const MAX_SUBJECT_GRADES = 3;

    /** Words that make a message about indicators. */
    private const INDICATOR_WORDS = ['ตัวชี้วัด', 'มาตรฐาน', 'indicator', 'สมรรถนะ', 'ผลการเรียนรู้', 'แบบฝึก', 'practice'];

    /** Words that make a message about gradebook settings. */
    private const GRADEBOOK_WORDS = ['สมุดคะแนน', 'หมวด', 'น้ำหนัก', 'เกณฑ์เกรด', 'ตัดเกรด', 'คะแนนเก็บ', 'กลางภาค', 'ปลายภาค', 'จิตพิสัย', 'gradebook'];

    /** Share of an assignment title's trigrams the message must contain. */
    private const TITLE_MATCH = 0.6;

    public static function build(User $teacher, string $message, ?int $previousTarget, ?string $previousCard = null): string
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
        $about = fn (array $words) => array_filter($words, fn (string $w) => mb_stripos($message, $w) !== false) !== [];
        if (in_array($previousCard, [AssistantPlans::CARD_INDICATORS, AssistantPlans::CARD_PRACTICE], true) || $about(self::INDICATOR_WORDS)) {
            array_push($lines, '', ...self::indicators($teacher, $courses->pluck('id')->all()));
        }
        if ($previousCard === AssistantPlans::CARD_GRADEBOOK || $about(self::GRADEBOOK_WORDS)) {
            array_push($lines, '', ...self::gradebooks($teacher));
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
        $questions = Question::query()->where('assignment_id', $a->id)->orderBy('position')->limit(100)
            ->get(['position', 'type', 'prompt_text', 'max_points', 'rubric_status']);
        foreach ($questions as $q) {
            $rubric = $q->rubric_status === Question::RUBRIC_NOT_NEEDED ? '' : ", rubric {$q->rubric_status}";
            $lines[] = "{$q->position}. {$q->type} (".rtrim(rtrim(number_format((float) $q->max_points, 2, '.', ''), '0'), '.')." pts{$rubric}): "
                .mb_substr(preg_replace('/\s+/u', ' ', $q->prompt_text) ?? '', 0, 80);
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

    /**
     * @param  list<int>  $courseIds
     * @return list<string>
     */
    private static function indicators(User $teacher, array $courseIds): array
    {
        $pairs = Course::query()->whereIn('courses.id', $courseIds)->with('subject:id,name')
            ->get(['id', 'subject_id', 'grade_level'])
            ->unique(fn (Course $c) => $c->subject_id.'-'.$c->grade_level)->take(self::MAX_SUBJECT_GRADES);
        $lines = ['Indicators of your subjects (code | level | name; * = in «ตัวชี้วัดของฉัน»; ครูเพิ่ม = made by a teacher):'];
        if ($pairs->isEmpty()) {
            $lines[] = '(no course yet: the teacher creates a course first)';

            return $lines;
        }
        $mine = array_flip(TeacherIndicators::ids($teacher));
        $levels = [Skill::LEVEL_STANDARD => 'standard', Skill::LEVEL_INDICATOR => 'indicator', Skill::LEVEL_SUB_INDICATOR => 'sub-indicator'];
        foreach ($pairs as $course) {
            $skills = Skill::query()->visibleToSchool($teacher->school_id)->notTrashed()
                ->where('subject_id', $course->subject_id)
                ->whereIn('level', array_keys($levels))
                ->where(fn ($q) => $q->whereNull('grade_level')->orWhere('grade_level', $course->grade_level))
                ->get(['id', 'code', 'name', 'level', 'source'])
                ->sort(fn (Skill $x, Skill $y) => strnatcmp($x->code, $y->code))->values();
            $lines[] = "## {$course->subject?->name} ".self::gradeLabel((int) $course->grade_level).' ('.$skills->count().')';
            foreach ($skills->take(self::MAX_SKILL_LINES) as $skill) {
                $lines[] = "- {$skill->code} | {$levels[$skill->level]} | ".mb_substr(preg_replace('/\s+/u', ' ', $skill->name) ?? '', 0, 120)
                    .(isset($mine[$skill->id]) ? ' *' : '').($skill->source === Skill::SOURCE_TEACHER ? ' (ครูเพิ่ม)' : '');
            }
            if ($skills->count() > self::MAX_SKILL_LINES) {
                $lines[] = '(more not listed)';
            }
        }

        return $lines;
    }

    /**
     * The gradebook settings of the teacher's own courses (DESIGN §37.9).
     *
     * @return list<string>
     */
    private static function gradebooks(User $teacher): array
    {
        $lines = ['Gradebook settings of your courses (course id | categories: name weight drop-lowest [items] | grade cutoffs for 4,3.5,3,2.5,2,1.5,1):'];
        $courses = Course::query()->where('created_by', $teacher->id)->where('school_id', $teacher->school_id)->latest('id')->limit(8)->get();
        foreach ($courses as $course) {
            $p = GradebookSettings::payload($course);
            $categories = $p['configured']
                ? implode('; ', array_map(fn (array $c) => "{$c['name']} ".GradebookSettings::formatWeight((float) $c['weight']).($c['drop_lowest'] > 0 ? " drop {$c['drop_lowest']}" : '')." [{$c['item_count']}]".($c['is_homework_default'] ? ' (homework default)' : ''), $p['categories']))
                : 'not set yet';
            $lines[] = "- {$course->id} {$course->code} {$course->name} | {$categories} | ".implode(',', $p['cutoffs']);
        }
        if ($courses->isEmpty()) {
            $lines[] = '(no course yet)';
        }

        return $lines;
    }

    private static function gradeLabel(int $level): string
    {
        return $level <= 6 ? "ป.{$level}" : 'ม.'.($level - 6);
    }

    private static function grade(Classroom $c): string
    {
        $level = (int) $c->grade_level;

        return $level <= 6 ? "ป.{$level}" : 'ม.'.($level - 6);
    }
}
