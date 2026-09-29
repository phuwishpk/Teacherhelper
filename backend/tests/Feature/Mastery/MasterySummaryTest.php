<?php

namespace Tests\Feature\Mastery;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\LessonPlan;
use App\Models\Mastery;
use App\Models\Skill;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §20.3, §20.7, §20.10: GET /courses/{id}/mastery-summary by
 * standard or by unit, per student and per classroom, with coverage; and
 * the student's own GET /student/courses/{id}/mastery-summary.
 *
 * The course (ค 1 → standards ค 1.1, ค 1.2):
 *   course_indicators  i1 (ค 1.1 ป.5/1), i5 (no standard above it)
 *   unit U1            i2 (ค 1.1 ป.5/2) + plan P1 in U1 with i1
 *   unit U2            nothing
 *   plan P2 (no unit)  i3 (ค 1.2 ป.5/1), i4 (a teacher's sub-indicator of i3)
 *
 * Mastery: A i1 0.8, i3 0.3, i4 0.6 (+ a skill outside the course);
 *          B i2 1.0; C nothing.
 */
class MasterySummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $room;

    private Course $course;

    /** @var array<string, Skill> */
    private array $s = [];

    /** @var array<string, User> */
    private array $students = [];

    private Unit $u1;

    private Unit $u2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        $math = Subject::factory()->create(['code' => 'ค', 'name' => 'คณิตศาสตร์']);
        $skill = fn (array $a) => Skill::factory()->create($a + ['subject_id' => $math->id, 'grade_level' => 5]);
        $strand = $skill(['code' => 'ค 1', 'name' => 'จำนวนและพีชคณิต', 'level' => Skill::LEVEL_STRAND, 'grade_level' => null]);
        $this->s['S1'] = $skill(['code' => 'ค 1.1', 'name' => 'เศษส่วน', 'level' => Skill::LEVEL_STANDARD, 'parent_id' => $strand->id, 'grade_level' => null]);
        $this->s['S2'] = $skill(['code' => 'ค 1.2', 'name' => 'ทศนิยม', 'level' => Skill::LEVEL_STANDARD, 'parent_id' => $strand->id, 'grade_level' => null]);
        $this->s['i1'] = $skill(['code' => 'ค 1.1 ป.5/1', 'parent_id' => $this->s['S1']->id]);
        $this->s['i2'] = $skill(['code' => 'ค 1.1 ป.5/2', 'parent_id' => $this->s['S1']->id]);
        $this->s['i3'] = $skill(['code' => 'ค 1.2 ป.5/1', 'parent_id' => $this->s['S2']->id]);
        $this->s['i4'] = $skill(['code' => 'ค 1.2 ป.5/1/ค1', 'parent_id' => $this->s['i3']->id, 'level' => Skill::LEVEL_SUB_INDICATOR, 'school_id' => $this->teacher->school_id, 'source' => Skill::SOURCE_TEACHER]);
        $this->s['i5'] = $skill(['code' => 'ค 9 ครู', 'parent_id' => null]);
        $this->s['x'] = $skill(['code' => 'ค 3.1 ป.5/1', 'parent_id' => null]);

        $this->room = $this->makeClassroom($this->teacher, ['grade_level' => 5]);
        foreach (['A', 'B', 'C'] as $n => $name) {
            $this->students[$name] = $this->enrollStudent($this->room, $n + 1, "นักเรียน {$name}")['student'];
        }

        $this->course = $this->makeCourse($this->teacher, [$this->room], ['subject_id' => $math->id]);
        $this->course->indicators()->sync([$this->s['i1']->id, $this->s['i5']->id]);
        $this->u1 = Unit::create(['course_id' => $this->course->id, 'position' => 1, 'title' => 'เศษส่วน']);
        $this->u2 = Unit::create(['course_id' => $this->course->id, 'position' => 2, 'title' => 'ยังไม่วางแผน']);
        $this->u1->indicators()->sync([$this->s['i2']->id]);
        LessonPlan::create(['course_id' => $this->course->id, 'unit_id' => $this->u1->id, 'position' => 1, 'title' => 'P1'])->indicators()->sync([$this->s['i1']->id]);
        LessonPlan::create(['course_id' => $this->course->id, 'position' => 2, 'title' => 'P2'])->indicators()->sync([$this->s['i3']->id, $this->s['i4']->id]);

        $this->mastery('A', 'i1', 0.8, 2);
        $this->mastery('A', 'i3', 0.3, 1);
        $this->mastery('A', 'i4', 0.6, 3);
        $this->mastery('A', 'x', 0.1, 5);
        $this->mastery('B', 'i2', 1.0, 1);
    }

    private function mastery(string $student, string $skill, float $value, int $nObs): void
    {
        Mastery::query()->insert(['student_id' => $this->students[$student]->id, 'skill_id' => $this->s[$skill]->id, 'value' => $value, 'n_obs' => $nObs, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function url(string $query): string
    {
        return "/api/v1/courses/{$this->course->id}/mastery-summary?{$query}";
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function values(array $node): array
    {
        // JSON drops the zero fraction: coverage 1.0 arrives as 1.
        return array_intersect_key($node, array_flip(['value', 'assessed', 'planned', 'coverage', 'passed', 'students_assessed', 'student_count']));
    }

    public function test_one_student_by_standard(): void
    {
        $a = $this->students['A'];
        $data = $this->asUser($this->teacher)->getJson($this->url("student_id={$a->id}&axis=standard"))->assertOk()->json('data');

        $this->assertSame(['student', 'standard', $a->id, null, 0.5], [$data['scope'], $data['axis'], $data['student_id'], $data['classroom_id'], $data['pass_threshold']]);
        // (0.8 + 0.3 + 0.6) / 3 = 0.5666… -> 0.567, 3 of 5 planned assessed.
        $this->assertSame(['value' => 0.567, 'assessed' => 3, 'planned' => 5, 'coverage' => 0.6, 'passed' => 2], $data['summary']);

        $this->assertSame(['standard', 'standard', 'other'], array_column($data['nodes'], 'type'));
        $this->assertSame(['ค 1.1', 'ค 1.2', null], array_column($data['nodes'], 'code'));
        $this->assertSame(['เศษส่วน', 'ทศนิยม', 'ไม่มีมาตรฐาน'], array_column($data['nodes'], 'title'));
        [$s1, $s2, $other] = $data['nodes'];
        $this->assertSame(['value' => 0.8, 'assessed' => 1, 'planned' => 2, 'coverage' => 0.5, 'passed' => 1], self::values($s1));
        // The teacher's sub-indicator reaches ค 1.2 through its parent indicator.
        $this->assertSame(['value' => 0.45, 'assessed' => 2, 'planned' => 2, 'coverage' => 1, 'passed' => 1], self::values($s2));
        $this->assertSame(['value' => null, 'assessed' => 0, 'planned' => 1, 'coverage' => 0, 'passed' => 0], self::values($other), 'not assessed yet: null, "ยังไม่ได้ประเมิน"');

        // The drill-down: every planned indicator of the node, the unassessed ones with null.
        $this->assertSame(['ค 1.1 ป.5/1', 'ค 1.1 ป.5/2'], array_map(fn ($i) => $i['skill']['code'], $s1['indicators']));
        $this->assertSame(['value' => 0.8, 'n_obs' => 2, 'level' => 'good', 'passed' => true], array_diff_key($s1['indicators'][0], ['skill' => 1]));
        $this->assertSame(['value' => null, 'n_obs' => 0, 'level' => null, 'passed' => null], array_diff_key($s1['indicators'][1], ['skill' => 1]));
        $this->assertSame('too_little', $s2['indicators'][0]['level'], 'n_obs 1');
        $this->assertSame('ครูเพิ่มเอง', $s2['indicators'][1]['skill']['source_label']);
        $this->assertArrayNotHasKey('students', $data);
    }

    public function test_one_student_by_unit(): void
    {
        $a = $this->students['A'];
        $data = $this->asUser($this->teacher)->getJson($this->url("student_id={$a->id}&classroom_id={$this->room->id}&axis=unit"))->assertOk()->json('data');

        $this->assertSame($this->room->id, $data['classroom_id']);
        $this->assertSame(['unit', 'unit', 'other'], array_column($data['nodes'], 'type'));
        $this->assertSame([$this->u1->id, $this->u2->id, null], array_column($data['nodes'], 'id'));
        $this->assertSame([1, 2, null], array_column($data['nodes'], 'position'));
        [$u1, $u2, $other] = $data['nodes'];
        // U1 = unit_indicators ∪ its plans' indicators = {i1, i2}.
        $this->assertSame(['value' => 0.8, 'assessed' => 1, 'planned' => 2, 'coverage' => 0.5, 'passed' => 1], self::values($u1));
        // An empty unit is listed (every planned node is a bar) with nothing planned.
        $this->assertSame(['value' => null, 'assessed' => 0, 'planned' => 0, 'coverage' => null, 'passed' => 0], self::values($u2));
        $this->assertSame([], $u2['indicators']);
        // Plan P2 has no unit and i5 is a course indicator only.
        $this->assertSame('ไม่อยู่ในหน่วย', $other['title']);
        $this->assertSame(['value' => 0.45, 'assessed' => 2, 'planned' => 3, 'coverage' => 0.667, 'passed' => 1], self::values($other));
        $this->assertSame(['value' => 0.567, 'assessed' => 3, 'planned' => 5, 'coverage' => 0.6, 'passed' => 2], $data['summary'], 'the course node is the same on both axes');
    }

    public function test_a_classroom_is_the_mean_of_its_students(): void
    {
        $data = $this->asUser($this->teacher)->getJson($this->url("classroom_id={$this->room->id}"))->assertOk()->json('data');

        $this->assertSame(['classroom', 'standard', $this->room->id], [$data['scope'], $data['axis'], $data['classroom_id']]);
        // A 0.567 and B 1.0 (C has nothing): (0.567 + 1.0) / 2 = 0.7835 -> 0.784; i1..i4 assessed by someone.
        $this->assertSame(['value' => 0.784, 'assessed' => 4, 'planned' => 5, 'coverage' => 0.8, 'students_assessed' => 2, 'student_count' => 3], $data['summary']);
        [$s1, $s2, $other] = $data['nodes'];
        $this->assertSame(['value' => 0.9, 'assessed' => 2, 'planned' => 2, 'coverage' => 1, 'students_assessed' => 2, 'student_count' => 3], self::values($s1));
        $this->assertSame(['value' => 0.45, 'assessed' => 2, 'planned' => 2, 'coverage' => 1, 'students_assessed' => 1, 'student_count' => 3], self::values($s2));
        $this->assertNull($other['value']);
        $this->assertSame(['value' => 0.8, 'assessed_students' => 1, 'passed_students' => 1], array_diff_key($s1['indicators'][0], ['skill' => 1]));
        $this->assertSame(['value' => 0.3, 'assessed_students' => 1, 'passed_students' => 0], array_diff_key($s2['indicators'][0], ['skill' => 1]));

        // Each student's course value, for picking one.
        $this->assertSame(['นักเรียน A', 'นักเรียน B', 'นักเรียน C'], array_column($data['students'], 'name'));
        $this->assertSame([0.567, 1, null], array_column($data['students'], 'value'));
        $this->assertSame([3, 1, 0], array_column($data['students'], 'assessed'));
        $this->assertSame([1, 2, 3], array_column($data['students'], 'student_number'));
    }

    public function test_the_teacher_query_is_checked(): void
    {
        $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/mastery-summary")
            ->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);
        $this->asUser($this->teacher)->getJson($this->url("classroom_id={$this->room->id}&axis=strand"))
            ->assertStatus(422)->assertJsonValidationErrors(['axis']);

        // A classroom of the teacher that does not study the course.
        $elsewhere = $this->makeClassroom($this->teacher);
        $outsider = $this->enrollStudent($elsewhere, 1, 'นักเรียนห้องอื่น')['student'];
        $this->asUser($this->teacher)->getJson($this->url("classroom_id={$elsewhere->id}"))
            ->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);
        $this->asUser($this->teacher)->getJson($this->url("student_id={$outsider->id}"))
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);
        $this->asUser($this->teacher)->getJson($this->url("student_id={$this->teacher->id}"))
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);

        // A course of another teacher: 404 (§20.9).
        $this->asUser($this->makeTeacher($this->teacher->school))->getJson($this->url("classroom_id={$this->room->id}"))->assertNotFound();
    }

    public function test_a_student_sees_only_their_own_values(): void
    {
        $a = $this->students['A'];
        $this->asUser($a)->getJson('/api/v1/student/courses')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->course->id)
            ->assertJsonPath('data.0.subject.code', 'ค')
            ->assertJsonPath('data.0.classroom_ids', [$this->room->id]);

        $data = $this->asUser($a)->getJson("/api/v1/student/courses/{$this->course->id}/mastery-summary?axis=unit")->assertOk()->json('data');
        $this->assertSame(['student', 'unit', $a->id], [$data['scope'], $data['axis'], $data['student_id']]);
        $this->assertSame(0.567, $data['summary']['value']);
        // No class average, no classmates (§20.4, §20.9).
        $this->assertArrayNotHasKey('students', $data);
        $this->assertArrayNotHasKey('students_assessed', $data['summary']);
        $this->assertStringNotContainsString('นักเรียน B', json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->asUser($a)->getJson("/api/v1/student/courses/{$this->course->id}/mastery-summary?axis=x")->assertStatus(422)->assertJsonValidationErrors(['axis']);

        // A course of a classroom the student is not in: 404; none listed.
        $elsewhere = $this->makeClassroom($this->teacher);
        $outsider = $this->enrollStudent($elsewhere, 1, 'นักเรียนห้องอื่น')['student'];
        $this->asUser($outsider)->getJson('/api/v1/student/courses')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($outsider)->getJson("/api/v1/student/courses/{$this->course->id}/mastery-summary")->assertNotFound();
    }

    public function test_the_pass_mark_follows_the_configuration(): void
    {
        config(['eduvision.mastery.pass_threshold' => 0.7]);
        $a = $this->students['A'];

        $data = $this->asUser($this->teacher)->getJson($this->url("student_id={$a->id}"))->assertOk()->json('data');

        $this->assertSame(0.7, $data['pass_threshold']);
        $this->assertSame(1, $data['summary']['passed'], 'only i1 (0.8) passes 0.7');
    }
}
