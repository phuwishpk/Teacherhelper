<?php

namespace Tests\Feature\Mastery;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\ClassroomStudent;
use App\Models\Course;
use App\Models\LessonPlan;
use App\Models\Mastery;
use App\Models\Question;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §20.4, §20.7, §20.10 "กราฟทุก endpoint": indicator progress (1),
 * % passing (2), the heatmap filters and standard groups (3), the score
 * distribution (4) and the course plan progress (5).
 *
 * The course (standards ค 1.1, ค 1.2 under strand ค 1):
 *   course_indicators  i1 (ค 1.1 ป.5/1), i5 (no standard)
 *   unit U1            i2 (ค 1.1 ป.5/2) + plan P1 (taught) with i1
 *   unit U2            nothing
 *   plan P2 (no unit)  i3 (ค 1.2 ป.5/1), not taught
 *
 * Mastery: A i1 0.8 (2 obs), i3 0.3 (1), x 0.9 (outside the course);
 *          B i1 0.4 (1), i2 1.0 (2); C nothing.
 */
class ChartsTest extends TestCase
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
        LessonPlan::create(['course_id' => $this->course->id, 'unit_id' => $this->u1->id, 'position' => 1, 'title' => 'P1', 'taught_on' => '2026-09-01'])->indicators()->sync([$this->s['i1']->id]);
        LessonPlan::create(['course_id' => $this->course->id, 'position' => 2, 'title' => 'P2'])->indicators()->sync([$this->s['i3']->id]);

        $this->mastery('A', 'i1', 0.8, 2);
        $this->mastery('A', 'i3', 0.3, 1);
        $this->mastery('A', 'x', 0.9, 3);
        $this->mastery('B', 'i1', 0.4, 1);
        $this->mastery('B', 'i2', 1.0, 2);
    }

    private function mastery(string $student, string $skill, float $value, int $nObs): void
    {
        Mastery::query()->insert(['student_id' => $this->students[$student]->id, 'skill_id' => $this->s[$skill]->id, 'value' => $value, 'n_obs' => $nObs, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function observe(string $student, string $skill, float $ratio, string $at, string $source = SkillObservation::SOURCE_HOMEWORK): void
    {
        SkillObservation::create(['student_id' => $this->students[$student]->id, 'skill_id' => $this->s[$skill]->id, 'source' => $source, 'score_ratio' => $ratio, 'observed_at' => $at]);
    }

    /**
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function ids(array $keys): array
    {
        return array_map(fn (string $k) => $this->s[$k]->id, $keys);
    }

    public function test_indicator_progress_replays_the_ewma_per_observation(): void
    {
        $this->observe('A', 'i1', 1.0, '2026-09-01 03:00:00');
        // 18:00 UTC is already the next day in Bangkok.
        $this->observe('A', 'i1', 0.5, '2026-09-02 18:00:00');
        $this->observe('A', 'i1', 0.0, '2026-09-04 03:00:00', SkillObservation::SOURCE_PRACTICE);
        $this->observe('A', 'i3', 0.3, '2026-09-05 03:00:00');
        $a = $this->students['A'];
        $ids = implode(',', $this->ids(['i3', 'i1']));

        $data = $this->asUser($this->teacher)->getJson("/api/v1/students/{$a->id}/indicator-progress?skill_ids={$ids}")->assertOk()->json('data');

        $this->assertSame($a->id, $data['student_id']);
        $this->assertSame($this->ids(['i1', 'i3']), $data['skill_ids'], 'lines in code order');
        [$i1, $i3] = $data['series'];
        // 1.0; 0.3·0.5 + 0.7·1.0 = 0.85; practice α 0.15: 0.85·0.85 = 0.7225 -> 0.723.
        $this->assertSame([1, 0.85, 0.723], array_column($i1['points'], 'value'));
        $this->assertSame(['2026-09-01', '2026-09-03', '2026-09-04'], array_column($i1['points'], 'date'));
        $this->assertSame('2026-09-02T18:00:00Z', $i1['points'][1]['observed_at']);
        $this->assertSame(['homework', 'homework', 'practice'], array_column($i1['points'], 'source'));
        $this->assertSame([0.3], array_column($i3['points'], 'value'));
        $this->assertSame('ค 1.2 ป.5/1', $i3['skill']['code']);
        // The picker: every skill the student has mastery for.
        $this->assertSame(['ค 1.1 ป.5/1', 'ค 1.2 ป.5/1', 'ค 3.1 ป.5/1'], array_map(fn ($r) => $r['skill']['code'], $data['skills']));

        // Without skill_ids: the skills observed most recently (at most 5).
        $default = $this->asUser($this->teacher)->getJson("/api/v1/students/{$a->id}/indicator-progress")->assertOk()->json('data');
        $this->assertSame($this->ids(['i1', 'i3']), $default['skill_ids']);

        // The student reads the same lines of their own; a classmate sees only theirs.
        $own = $this->asUser($a)->getJson("/api/v1/student/indicator-progress?skill_ids={$this->s['i1']->id}")->assertOk()->json('data');
        $this->assertSame([1, 0.85, 0.723], array_column($own['series'][0]['points'], 'value'));
        $other = $this->asUser($this->students['B'])->getJson("/api/v1/student/indicator-progress?skill_ids={$this->s['i1']->id}")->assertOk()->json('data');
        $this->assertSame($this->students['B']->id, $other['student_id']);
        $this->assertSame([], $other['series'][0]['points']);
    }

    public function test_indicator_progress_checks_the_skill_ids(): void
    {
        $a = $this->students['A'];
        $url = "/api/v1/students/{$a->id}/indicator-progress?skill_ids=";
        $six = implode(',', range(1, 6));
        $this->asUser($this->teacher)->getJson($url.$six)->assertStatus(422)->assertJsonValidationErrors(['skill_ids']);
        $this->asUser($this->teacher)->getJson($url.'abc')->assertStatus(422)->assertJsonValidationErrors(['skill_ids']);
        $this->asUser($this->teacher)->getJson($url.'999999')->assertStatus(422)->assertJsonValidationErrors(['skill_ids']);
        // A skill of another school is not visible.
        $foreign = Skill::factory()->create(['school_id' => $this->makeSchool()->id, 'code' => 'ต่างโรงเรียน']);
        $this->asUser($this->teacher)->getJson($url.$foreign->id)->assertStatus(422)->assertJsonValidationErrors(['skill_ids']);
        // Duplicates count once.
        $this->asUser($this->teacher)->getJson($url."{$this->s['i1']->id},{$this->s['i1']->id}")->assertOk()->assertJsonPath('data.skill_ids', [$this->s['i1']->id]);

        // A student of another teacher's classroom: 404.
        $this->asUser($this->makeTeacher($this->teacher->school))->getJson($url.$this->s['i1']->id)->assertNotFound();
    }

    public function test_the_pass_rate_per_indicator(): void
    {
        $data = $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->room->id}/indicator-pass-rate?course_id={$this->course->id}")->assertOk()->json('data');

        $this->assertSame([$this->room->id, $this->course->id, 0.5, 3], [$data['classroom_id'], $data['course_id'], $data['pass_threshold'], $data['student_count']]);
        $this->assertSame(['ค 1.1 ป.5/1', 'ค 1.1 ป.5/2', 'ค 1.2 ป.5/1', 'ค 9 ครู'], array_map(fn ($r) => $r['skill']['code'], $data['indicators']));
        $rows = array_map(fn ($r) => [$r['assessed_students'], $r['passed_students'], $r['pass_rate']], $data['indicators']);
        // i1: A 0.8 passes, B 0.4 does not; i5 is planned but nobody is assessed.
        $this->assertSame([[2, 1, 0.5], [1, 1, 1], [1, 0, 0], [0, 0, null]], $rows);

        // Without a course: every skill with mastery in the classroom, the course's unassessed ones absent.
        $all = $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->room->id}/indicator-pass-rate")->assertOk()->json('data');
        $this->assertNull($all['course_id']);
        $this->assertSame(['ค 1.1 ป.5/1', 'ค 1.1 ป.5/2', 'ค 1.2 ป.5/1', 'ค 3.1 ป.5/1'], array_map(fn ($r) => $r['skill']['code'], $all['indicators']));

        // B's Google account left the linked course: B stays in the room but not in the class figures.
        ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('student_id', $this->students['B']->id)->update(['left_course_at' => now()]);
        $left = $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->room->id}/indicator-pass-rate?course_id={$this->course->id}")->assertOk()->json('data');
        $this->assertSame(2, $left['student_count']);
        $this->assertSame([[1, 1, 1], [0, 0, null], [1, 0, 0], [0, 0, null]], array_map(fn ($r) => [$r['assessed_students'], $r['passed_students'], $r['pass_rate']], $left['indicators']));
        $summary = $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/mastery-summary?classroom_id={$this->room->id}")->assertOk()->json('data');
        $this->assertSame(['นักเรียน A', 'นักเรียน C'], array_column($summary['students'], 'name'));
        ClassroomStudent::query()->where('classroom_id', $this->room->id)->update(['left_course_at' => null]);

        // A course the classroom does not study: 422.
        $elsewhere = $this->makeCourse($this->teacher, [], ['code' => 'ค15102']);
        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->room->id}/indicator-pass-rate?course_id={$elsewhere->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['course_id']);
    }

    public function test_the_heatmap_filters_by_course_and_unit_and_groups_by_standard(): void
    {
        $url = "/api/v1/classrooms/{$this->room->id}/mastery";

        $data = $this->asUser($this->teacher)->getJson("{$url}?course_id={$this->course->id}")->assertOk()->json('data');
        $this->assertSame([$this->course->id, null], [$data['course_id'], $data['unit_id']]);
        // Every planned indicator is a column, assessed or not; x (outside the course) is not.
        $this->assertSame($this->ids(['i1', 'i2', 'i3', 'i5']), array_column($data['skills'], 'id'));
        $this->assertSame(['ค 1.1', 'ค 1.2', null], array_map(fn ($g) => $g['standard']['code'] ?? null, $data['groups']));
        $this->assertSame([$this->ids(['i1', 'i2']), $this->ids(['i3']), $this->ids(['i5'])], array_column($data['groups'], 'skill_ids'));
        $this->assertNotContains($this->s['x']->id, array_column($data['cells'], 'skill_id'));
        $this->assertCount(4, $data['cells']);

        $unit = $this->asUser($this->teacher)->getJson("{$url}?course_id={$this->course->id}&unit_id={$this->u1->id}")->assertOk()->json('data');
        $this->assertSame($this->u1->id, $unit['unit_id']);
        $this->assertSame($this->ids(['i1', 'i2']), array_column($unit['skills'], 'id'));
        $this->assertSame([[$this->ids(['i1', 'i2'])]], [array_column($unit['groups'], 'skill_ids')]);
        $this->asUser($this->teacher)->getJson("{$url}?course_id={$this->course->id}&unit_id={$this->u2->id}")->assertOk()->assertJsonPath('data.skills', []);

        // Unfiltered (the §14.3 heatmap): skills with mastery, grouped too.
        $plain = $this->asUser($this->teacher)->getJson($url)->assertOk()->json('data');
        $this->assertSame($this->ids(['i1', 'i2', 'i3', 'x']), array_column($plain['skills'], 'id'));
        $this->assertNull(end($plain['groups'])['standard']);

        // unit_id needs course_id, and must be a unit of that course.
        $this->asUser($this->teacher)->getJson("{$url}?unit_id={$this->u1->id}")->assertStatus(422)->assertJsonValidationErrors(['course_id']);
        $other = $this->makeCourse($this->teacher, [$this->room], ['code' => 'ค15102']);
        $this->asUser($this->teacher)->getJson("{$url}?course_id={$other->id}&unit_id={$this->u1->id}")->assertStatus(422)->assertJsonValidationErrors(['unit_id']);
    }

    public function test_the_score_distribution_of_published_submissions(): void
    {
        $assignment = Assignment::factory()->for_classroom($this->room)->create(['subject_id' => $this->course->subject_id, 'status' => Assignment::STATUS_READY]);
        Question::factory()->create(['assignment_id' => $assignment->id, 'max_points' => 4]);
        Question::factory()->create(['assignment_id' => $assignment->id, 'max_points' => 6]);
        $students = [...array_values($this->students)];
        foreach (range(4, 8) as $n) {
            $students[] = $this->enrollStudent($this->room, $n, "นักเรียน {$n}")['student'];
        }
        $published = fn (User $s, ?float $total, ?float $override = null) => Submission::create([
            'assignment_id' => $assignment->id, 'student_id' => $s->id, 'status' => Submission::STATUS_PUBLISHED,
            'total_score' => $total, 'total_override' => $override, 'published_at' => now(),
        ]);
        $published($students[0], 10);
        $published($students[1], 7.5);
        $published($students[2], 3);
        $published($students[3], 5, 12); // the Classroom total counts, above full marks: last bin
        $published($students[4], 0);
        $published($students[5], null); // published without a total: not scored
        Submission::create(['assignment_id' => $assignment->id, 'student_id' => $students[6]->id, 'status' => Submission::STATUS_REVIEWED, 'total_score' => 9]);

        $data = $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$assignment->id}/score-distribution")->assertOk()->json('data');

        $this->assertSame([10, 6, 5], [$data['max_points'], $data['published_count'], $data['scored_count']]);
        // 0, 3, 7.5, 10, 12: mean 6.5, median 7.5.
        $this->assertSame([6.5, 7.5, 0.65, 0.75], [$data['mean'], $data['median'], $data['mean_ratio'], $data['median_ratio']]);
        $this->assertSame([1, 0, 0, 1, 0, 0, 0, 1, 0, 2], array_column($data['bins'], 'count'));
        $this->assertSame([0, 0.1, 0, 1], [$data['bins'][0]['from_ratio'], $data['bins'][0]['to_ratio'], $data['bins'][0]['from_points'], $data['bins'][0]['to_points']]);
        $this->assertSame([9, 10], [$data['bins'][9]['from_points'], $data['bins'][9]['to_points']]);

        // No questions: no bins; nothing published: no mean.
        $empty = Assignment::factory()->for_classroom($this->room)->create(['subject_id' => $this->course->subject_id]);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$empty->id}/score-distribution")->assertOk()
            ->assertJsonPath('data.bins', [])->assertJsonPath('data.mean', null)->assertJsonPath('data.published_count', 0);
        $this->asUser($this->makeTeacher($this->teacher->school))->getJson("/api/v1/assignments/{$assignment->id}/score-distribution")->assertNotFound();
    }

    public function test_the_plan_progress_per_unit(): void
    {
        $room2 = $this->makeClassroom($this->teacher);
        $this->course->classrooms()->attach($room2->id);
        // A published assignment of the course assesses i2 and i3; an unpublished one (i1) does not count.
        $done = Assignment::factory()->for_classroom($this->room)->create(['subject_id' => $this->course->subject_id, 'course_id' => $this->course->id, 'status' => Assignment::STATUS_READY]);
        Question::factory()->create(['assignment_id' => $done->id])->skills()->attach($this->s['i2']->id);
        Question::factory()->create(['assignment_id' => $done->id])->skills()->attach($this->s['i3']->id);
        Submission::create(['assignment_id' => $done->id, 'student_id' => $this->students['A']->id, 'status' => Submission::STATUS_PUBLISHED, 'total_score' => 1, 'published_at' => now()]);
        $pending = Assignment::factory()->for_classroom($this->room)->create(['subject_id' => $this->course->subject_id, 'course_id' => $this->course->id, 'status' => Assignment::STATUS_READY]);
        Question::factory()->create(['assignment_id' => $pending->id])->skills()->attach($this->s['i1']->id);
        Submission::create(['assignment_id' => $pending->id, 'student_id' => $this->students['A']->id, 'status' => Submission::STATUS_NEEDS_REVIEW]);

        $data = $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/plan-progress")->assertOk()->json('data');

        $keys = ['planned', 'taught', 'assessed', 'taught_not_assessed', 'not_taught', 'plans_total', 'plans_taught'];
        $pick = fn (array $row) => array_values(array_intersect_key($row, array_flip($keys)));
        $this->assertSame($this->course->id, $data['course']['id']);
        $this->assertNull($data['classroom_id']);
        // i1, i2, i3, i5 planned; i1 taught (P1); i2, i3 assessed.
        $this->assertSame([4, 1, 2, 1, 1, 2, 1], $pick($data['summary']));
        $this->assertSame(['unit', 'unit', 'other'], array_column($data['units'], 'type'));
        $this->assertSame([$this->u1->id, $this->u2->id, null], array_column($data['units'], 'id'));
        [$u1, $u2, $other] = $data['units'];
        $this->assertSame([2, 1, 1, 1, 0, 1, 1], $pick($u1));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $pick($u2));
        $this->assertSame('ไม่อยู่ในหน่วย', $other['title']);
        $this->assertSame([2, 0, 1, 0, 1, 1, 0], $pick($other), 'i3 assessed, i5 not taught; P2 has no unit');

        // Per classroom: room2 has published nothing.
        $room = $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/plan-progress?classroom_id={$room2->id}")->assertOk()->json('data');
        $this->assertSame([4, 1, 0, 1, 3, 2, 1], $pick($room['summary']));
        $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/plan-progress?classroom_id={$this->makeClassroom($this->teacher)->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);
        $this->asUser($this->makeTeacher($this->teacher->school))->getJson("/api/v1/courses/{$this->course->id}/plan-progress")->assertNotFound();
    }
}
