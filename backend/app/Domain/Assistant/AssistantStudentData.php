<?php

namespace App\Domain\Assistant;

use App\Domain\Analysis\AnalysisInputs;
use App\Domain\Attendance\AttendanceBook;
use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Exams\ExamOptionAnalysis;
use App\Domain\Gradebook\ClassroomGradebook;
use App\Domain\Gradebook\GradebookCalculator;
use App\Domain\Gradebook\GradeCutoffs;
use App\Domain\Mastery\ItemAnalysis;
use App\Domain\Mastery\ScoreDistribution;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\AssistantConversation;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Submission;
use App\Models\User;

/**
 * The "STUDENT DATA" of an assistant answer (DESIGN §37.8): numbers the
 * server computes with the screens' own services, every student written
 * as the conversation's label (AssistantStudents). Never a name, a number
 * (เลขที่), an id, a contact or anything a student wrote.
 *
 * - classroom(): per course of the classroom the teacher may read (all for
 *   the homeroom teacher, their own for a subject teacher) the gradebook
 *   per student (total, grade, missing items, attendance rate), each
 *   item's average, and the indicators the class is weakest in.
 * - student(): per course the student's items (score, percent, missing,
 *   late), total and grade, attendance, strengths and areas.
 * - assignment(): the distribution, each question's success rate and
 *   discrimination, the wrong options chosen most (exams), the students
 *   not submitted yet and the lowest and highest totals.
 *
 * Access follows the screens: ClassroomAccess::for, ::forStudent and
 * ::seesResults; anything else is 404 not_found.
 */
final class AssistantStudentData
{
    public const SET_CLASSROOM = 'classroom_summary';

    public const SET_STUDENT = 'student_history';

    public const SET_ASSIGNMENT = 'assignment_analysis';

    public const SETS = [self::SET_CLASSROOM, self::SET_STUDENT, self::SET_ASSIGNMENT];

    /** Characters of data per answer. */
    public const MAX_CHARS = 30000;

    public function __construct(
        private readonly AssistantStudents $students,
        private readonly AnalysisInputs $analysis,
    ) {}

    /** Whether the teacher may send students' results to Gemini at all. */
    public static function allowed(User $teacher): bool
    {
        return (bool) ($teacher->school?->assistant_student_data ?? true);
    }

    public static function enabled(User $teacher): bool
    {
        return self::allowed($teacher) && $teacher->assistant_data_ack_at !== null;
    }

    /**
     * @param  array{set: string, classroom_id?: int|null, student_id?: int|null, student_label?: string|null, assignment_id?: int|null}  $scope
     *
     * @throws ApiException 404 not_found, 422 validation_failed
     */
    public function build(User $teacher, AssistantConversation $conversation, array $scope): string
    {
        $text = match ($scope['set']) {
            self::SET_CLASSROOM => $this->classroom($teacher, $conversation, $this->classroomOf($teacher, $scope['classroom_id'] ?? null)),
            self::SET_STUDENT => $this->student($teacher, $conversation, $scope),
            self::SET_ASSIGNMENT => $this->assignment($teacher, $conversation, (int) ($scope['assignment_id'] ?? 0)),
            default => throw new ApiException('ไม่รู้จักชุดข้อมูลนี้', 'validation_failed', 422),
        };

        return mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS)."\n(cut: too long)" : $text;
    }

    private function classroom(User $teacher, AssistantConversation $c, Classroom $classroom): string
    {
        $lines = ["# STUDENT DATA: classroom {$classroom->name} (".$classroom->currentStudents()->count().' students; labels only)'];
        foreach ($this->courses($teacher, $classroom) as $course) {
            $grid = ClassroomGradebook::of($course, $classroom)->grid();
            $attendance = AttendanceBook::totals($course, $classroom->id);
            $lines[] = '';
            $lines[] = "## Course {$course->code} {$course->name}".($grid['configured'] ? '' : ' (gradebook categories not set yet: no totals)');
            $columns = array_values(array_filter($grid['columns'], fn (array $col) => ! $col['is_attendance']));
            $lines[] = 'Items: '.implode('; ', array_map(fn (array $col, int $i) => ($i + 1).") {$col['name']} ({$col['kind']}, full ".self::num($col['full_marks']).')', $columns, array_keys($columns)));
            $sums = [];
            $grades = [];
            $lines[] = 'Per student (label | total % | grade | missing items | attendance rate):';
            foreach ($grid['rows'] as $row) {
                $label = $this->students->label($c, (int) $row['student_id'], $classroom->id);
                $missing = [];
                foreach ($columns as $i => $col) {
                    $cell = ((array) ($row['cells'] ?? []))[$col['key']] ?? null;
                    if ($cell === null) {
                        continue;
                    }
                    if (($cell['state'] ?? null) === 'missing') {
                        $missing[] = $i + 1;
                    } elseif (is_numeric($cell['percent'] ?? null)) {
                        $sums[$i][] = (float) $cell['percent'];
                    }
                }
                $grade = GradeCutoffs::label($row['grade'] ?? null, $row['special'] ?? null);
                $grades[$grade ?? '-'] = ($grades[$grade ?? '-'] ?? 0) + 1;
                $rate = $attendance[$row['student_id']]['rate'] ?? null;
                $lines[] = "- {$label} | ".(isset($row['total']) ? self::num($row['total']) : '-').' | '.($grade ?? '-').' | '
                    .($missing === [] ? '0' : count($missing).' (items '.implode(',', $missing).')').' | '.($rate === null ? '-' : self::num($rate * 100).'%');
            }
            $lines[] = 'Item averages of the scored: '.implode('; ', array_map(
                fn (array $col, int $i) => ($i + 1).') '.(isset($sums[$i]) ? self::num(array_sum($sums[$i]) / count($sums[$i])).'% (n '.count($sums[$i]).')' : '-'),
                $columns, array_keys($columns),
            ));
            ksort($grades);
            $lines[] = 'Grades: '.implode(', ', array_map(fn ($g, $n) => "{$g}: {$n}", array_keys($grades), $grades));
        }
        $weak = [];
        foreach ($this->analysis->forClassroom($classroom) as $input) {
            foreach ($input->entries as $e) {
                $weak[$e['skill']->id]['skill'] = $e['skill'];
                $weak[$e['skill']->id]['values'][] = $e['value'];
            }
        }
        usort($weak, fn (array $a, array $b) => array_sum($a['values']) / count($a['values']) <=> array_sum($b['values']) / count($b['values']));
        $lines[] = '';
        $lines[] = 'Indicators, weakest class average first (code | name | mean mastery % | students assessed):';
        foreach (array_slice($weak, 0, 8) as $w) {
            $lines[] = "- {$w['skill']->code} | ".mb_substr($w['skill']->name, 0, 100).' | '.self::num(100 * array_sum($w['values']) / count($w['values'])).'% | '.count($w['values']);
        }
        if ($weak === []) {
            $lines[] = '(no indicator assessed yet)';
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $scope */
    private function student(User $teacher, AssistantConversation $c, array $scope): string
    {
        $studentId = isset($scope['student_id']) ? (int) $scope['student_id'] : null;
        $classroomId = isset($scope['classroom_id']) ? (int) $scope['classroom_id'] : null;
        if ($studentId === null && isset($scope['student_label'])) {
            $row = $this->students->student($c, (string) $scope['student_label']);
            [$studentId, $classroomId] = [$row?->student_id, $classroomId ?? $row?->classroom_id];
        }
        $student = $studentId === null ? null : User::query()->where('role', User::ROLE_STUDENT)->find($studentId);
        $access = $student === null ? null : ClassroomAccess::forStudent($teacher, $student);
        if ($student === null || $access === null) {
            throw new ApiException('ไม่พบนักเรียนคนนี้ในห้องที่ครูสอน', 'not_found', 404);
        }
        $classrooms = ClassroomAccess::classrooms($teacher)->whereHas('students', fn ($q) => $q->whereKey($student->id))->get();
        $classroom = $classrooms->firstWhere('id', $classroomId) ?? $classrooms->first();
        if ($classroom === null) {
            throw new ApiException('ไม่พบนักเรียนคนนี้ในห้องที่ครูสอน', 'not_found', 404);
        }
        $label = $this->students->label($c, $student->id, $classroom->id);
        $lines = ["# STUDENT DATA: {$label} in classroom {$classroom->name}"];
        foreach ($this->courses($teacher, $classroom) as $course) {
            $book = ClassroomGradebook::of($course, $classroom);
            $row = collect($book->grid()['rows'])->firstWhere('student_id', $student->id);
            if ($row === null) {
                continue;
            }
            $late = Submission::query()->where('student_id', $student->id)->where('late', true)
                ->whereIn('assignment_id', Assignment::query()->select('id')->where('course_id', $course->id))->pluck('assignment_id')->all();
            $attendance = AttendanceBook::totals($course, $classroom->id)[$student->id] ?? null;
            $grade = GradeCutoffs::label($row['grade'] ?? null, $row['special'] ?? null);
            $lines[] = '';
            $lines[] = "## Course {$course->code} {$course->name}: total ".(isset($row['total']) ? self::num($row['total']).'%' : '-').', grade '.($grade ?? '-')
                .($attendance === null ? '' : ', attendance '.implode(' ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($attendance['counts']), $attendance['counts']))
                    .($attendance['rate'] === null ? '' : ' (rate '.self::num($attendance['rate'] * 100).'%)'));
            $lines[] = 'Items (name | score/full | % | สถานะ):';
            foreach ($book->grid()['columns'] as $col) {
                if ($col['is_attendance']) {
                    continue;
                }
                $cell = ((array) ($row['cells'] ?? []))[$col['key']] ?? [];
                $lines[] = "- {$col['name']} | ".(isset($cell['score']) ? self::num($cell['score']) : '-').'/'.self::num($col['full_marks']).' | '
                    .(isset($cell['percent']) ? self::num($cell['percent']).'%' : '-').' | '.self::state($cell['state'] ?? null)
                    .($col['type'] === 'assignment' && in_array($col['id'], $late, true) ? ', late' : '');
            }
        }
        $input = $this->analysis->forStudent($student->id, $classroom);
        $byId = [];
        foreach ($input->entries as $e) {
            $byId[$e['skill']->id] = $e['skill'];
        }
        $describe = fn (array $rows) => $rows === [] ? '(none yet)' : implode('; ', array_map(
            fn (array $r) => "{$byId[$r['skill_id']]->code} ".mb_substr($byId[$r['skill_id']]->name, 0, 80).' '.self::num($r['value'] * 100).'% (n '.$r['n_obs'].')',
            $rows,
        ));
        $lines[] = '';
        $lines[] = 'Strengths: '.$describe($input->strengths());
        $lines[] = 'Areas to improve: '.$describe($input->areas());

        return implode("\n", $lines);
    }

    private function assignment(User $teacher, AssistantConversation $c, int $assignmentId): string
    {
        $assignment = Assignment::query()->with('classroom')->find($assignmentId);
        if ($assignment === null || ! ClassroomAccess::seesResults($teacher, $assignment)) {
            throw new ApiException('ไม่พบงานนี้ในงานที่ครูดูผลได้', 'not_found', 404);
        }
        $dist = ScoreDistribution::forAssignment($assignment);
        $items = ItemAnalysis::forAssignment($assignment);
        $lines = [
            "# STUDENT DATA: assignment \"{$assignment->title}\" ({$assignment->kind}) in {$assignment->classroom?->name}, full marks ".self::num($dist['max_points']),
            "Published results: {$dist['published_count']}; mean ".self::num($dist['mean']).', median '.self::num($dist['median']),
            'Distribution by 10%: '.implode(' ', array_map(fn (array $b) => (string) ($b['count'] ?? 0), $dist['bins'])),
            'Questions (no | type | full | success rate p | discrimination r):',
        ];
        foreach ($items['items'] as $q) {
            $lines[] = "- {$q['position']} | {$q['type']} | ".self::num($q['max_points']).' | '.self::num($q['p'] === null ? null : $q['p'] * 100).'% | '.self::num($q['r']);
        }
        if ($assignment->isExam()) {
            $lines[] = 'Wrong options chosen most:';
            foreach (ExamOptionAnalysis::of($assignment)['questions'] as $q) {
                $wrong = collect($q['options'] ?? [])->where('correct', false)->sortByDesc('count')->first();
                if ($wrong !== null && ($wrong['count'] ?? 0) > 0) {
                    $lines[] = '- question '.($q['position'] ?? '?').": {$wrong['label']} chosen by ".self::num($wrong['pct'] ?? null).'%';
                }
            }
        }
        $submissions = Submission::query()->where('assignment_id', $assignment->id)->get();
        $roster = $assignment->classroom?->currentStudents()->pluck('users.id')->map(fn ($id) => (int) $id)->all() ?? [];
        $without = array_values(array_diff($roster, $submissions->pluck('student_id')->map(fn ($id) => (int) $id)->all()));
        $lines[] = 'No submission yet: '.($without === [] ? 'none' : implode(', ', array_map(fn (int $id) => $this->students->label($c, $id, $assignment->classroom_id), $without)));
        $lines[] = 'Late: '.$submissions->where('late', true)->count().', waiting for review or publishing: '.$submissions->where('status', '!=', Submission::STATUS_PUBLISHED)->count();
        $published = $submissions->where('status', Submission::STATUS_PUBLISHED)
            ->map(fn (Submission $s) => [$s->student_id, $s->effectiveTotal()])->filter(fn (array $r) => $r[1] !== null)->sortBy(fn (array $r) => $r[1])->values();
        $show = fn ($rows) => $rows->map(fn (array $r) => $this->students->label($c, (int) $r[0], $assignment->classroom_id).' '.self::num($r[1]))->implode(', ');
        $lines[] = 'Lowest totals: '.($published->isEmpty() ? '-' : $show($published->take(5)));
        $lines[] = 'Highest totals: '.($published->isEmpty() ? '-' : $show($published->reverse()->take(3)));

        return implode("\n", $lines);
    }

    private function classroomOf(User $teacher, mixed $id): Classroom
    {
        $classroom = filter_var($id, FILTER_VALIDATE_INT) === false ? null : Classroom::query()->find((int) $id);
        if ($classroom === null || ClassroomAccess::for($teacher, $classroom) === null) {
            throw new ApiException('ไม่พบห้องนี้ในห้องที่ครูสอน', 'not_found', 404);
        }

        return $classroom;
    }

    /**
     * The courses of the classroom whose results the teacher reads: every
     * bound course for the homeroom teacher, their own for anyone else.
     *
     * @return list<Course>
     */
    private function courses(User $teacher, Classroom $classroom): array
    {
        $access = ClassroomAccess::for($teacher, $classroom);
        if ($access === null) {
            return [];
        }
        $query = Course::query()->whereHas('classrooms', fn ($q) => $q->whereKey($classroom->id))->orderBy('id');
        if (! $access->isHomeroom()) {
            $query->whereIn('id', $access->courseIds);
        }

        return $query->limit(6)->get()->all();
    }

    /** A gradebook cell state in the words of the gradebook screen. */
    private static function state(?string $state): string
    {
        return match ($state) {
            GradebookCalculator::SCORED => 'มีคะแนน',
            GradebookCalculator::MISSING => 'ไม่ส่ง (เลยกำหนด)',
            GradebookCalculator::NOT_DUE => 'ยังไม่ถึงกำหนดส่ง',
            GradebookCalculator::PENDING => 'ส่งแล้ว รอตรวจหรือรอเผยแพร่',
            GradebookCalculator::NOT_COUNTED => 'ไม่นำมาคิดเกรด',
            GradebookCalculator::EXCUSED => 'ยกเว้น',
            GradebookCalculator::NOT_ASSIGNED => 'ไม่ได้มอบหมาย',
            default => '-',
        };
    }

    private static function num(mixed $v): string
    {
        return $v === null || ! is_numeric($v) ? '-' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    }
}
