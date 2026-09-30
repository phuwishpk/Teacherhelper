<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\LessonPlan;
use App\Models\Question;
use App\Models\Skill;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §20.1, §20.7, §20.10: courses, units and lesson plans (CRUD and
 * who may touch them), courses bound to many classrooms, and assignments
 * that must pick a course of their classroom.
 */
class CourseTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Subject $math;

    private Skill $indicator;

    private Skill $indicator2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        $this->math = Subject::factory()->create(['code' => 'ค', 'name' => 'คณิตศาสตร์']);
        $standard = Skill::factory()->create(['subject_id' => $this->math->id, 'code' => 'ค 1.1', 'level' => Skill::LEVEL_STANDARD, 'grade_level' => null]);
        $this->indicator = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $standard->id, 'code' => 'ค 1.1 ป.5/1', 'grade_level' => 5]);
        $this->indicator2 = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $standard->id, 'code' => 'ค 1.1 ป.5/2', 'grade_level' => 5]);
    }

    /** @return array<string, mixed> */
    private function courseBody(array $overrides = []): array
    {
        return $overrides + [
            'code' => ' ค15101 ',
            'name' => 'คณิตศาสตร์ 5',
            'subject_id' => $this->math->id,
            'grade_level' => 5,
            'semester' => 1,
            'academic_year' => 2569,
            'hours' => 160,
            'description' => 'ศึกษาเศษส่วนและทศนิยม',
        ];
    }

    public function test_a_teacher_creates_a_course_once_and_binds_it_to_several_classrooms(): void
    {
        $a = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1']);
        $b = $this->makeClassroom($this->teacher, ['name' => 'ป.5/2']);

        $created = $this->asUser($this->teacher)->postJson('/api/v1/courses', $this->courseBody([
            'classroom_ids' => [$a->id, $b->id],
            'skill_ids' => [$this->indicator->id],
        ]))->assertCreated()
            ->assertJsonPath('data.code', 'ค15101')
            ->assertJsonPath('data.semester', 1)
            ->assertJsonPath('data.academic_year', 2569)
            ->assertJsonPath('data.subject.code', 'ค')
            ->assertJsonPath('data.classrooms.0.name', 'ป.5/1')
            ->assertJsonPath('data.indicators.0.code', 'ค 1.1 ป.5/1')
            ->assertJsonPath('data.indicators.0.level', 'indicator')
            ->assertJsonPath('data.units', [])
            ->assertJsonPath('data.lesson_plans', []);
        $id = $created->json('data.id');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $created->json('data.classroom_ids'));

        // A classroom has several courses too.
        $other = $this->asUser($this->teacher)->postJson('/api/v1/courses', $this->courseBody(['code' => 'ค15102', 'classroom_ids' => [$a->id]]))->assertCreated()->json('data.id');
        $this->assertEqualsCanonicalizing([$id, $other], array_column($this->asUser($this->teacher)->getJson("/api/v1/courses?classroom_id={$a->id}")->assertOk()->json('data'), 'id'));
        $this->asUser($this->teacher)->getJson("/api/v1/courses?classroom_id={$b->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        // PUT classrooms replaces the set.
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$id}/classrooms", ['classroom_ids' => [$b->id]])
            ->assertOk()
            ->assertJsonPath('data.classroom_ids', [$b->id]);
        // Only the teacher's own classrooms.
        $colleagueRoom = $this->makeClassroom($this->makeTeacher($this->teacher->school));
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$id}/classrooms", ['classroom_ids' => [$b->id, $colleagueRoom->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['classroom_ids.1']);
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$id}/classrooms", ['classroom_ids' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['classroom_ids']);

        // The same code in the same year and semester is taken; another semester is not.
        $this->asUser($this->teacher)->postJson('/api/v1/courses', $this->courseBody())->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->asUser($this->teacher)->postJson('/api/v1/courses', $this->courseBody(['semester' => 2]))->assertCreated();
        $this->asUser($this->teacher)->postJson('/api/v1/courses', ['code' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'subject_id', 'grade_level', 'academic_year']);
        $this->asUser($this->teacher)->postJson('/api/v1/courses', $this->courseBody(['code' => 'ค1', 'academic_year' => 2026]))->assertStatus(422)->assertJsonValidationErrors(['academic_year']);
    }

    public function test_a_create_racing_another_one_of_the_same_code_is_422_not_500(): void
    {
        // The other create commits between the code check and this insert.
        $raced = false;
        Course::creating(function (Course $course) use (&$raced) {
            if (! $raced) {
                $raced = true;
                Course::withoutEvents(fn () => Course::query()->insert($course->getAttributes() + ['created_at' => now(), 'updated_at' => now()]));
            }
        });

        $this->asUser($this->teacher)->postJson('/api/v1/courses', $this->courseBody())
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['code']);
        $this->assertTrue($raced);
    }

    public function test_course_indicators_are_indicators_or_sub_indicators_the_school_sees(): void
    {
        $course = $this->makeCourse($this->teacher);
        $standard = Skill::query()->where('code', 'ค 1.1')->firstOrFail();
        $otherSchool = Skill::factory()->create(['subject_id' => $this->math->id, 'school_id' => $this->makeSchool()->id, 'parent_id' => $this->indicator->id, 'level' => Skill::LEVEL_SUB_INDICATOR]);
        $ownSchool = Skill::factory()->create(['subject_id' => $this->math->id, 'school_id' => $this->teacher->school_id, 'parent_id' => $this->indicator->id, 'level' => Skill::LEVEL_SUB_INDICATOR, 'source' => Skill::SOURCE_TEACHER]);

        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$course->id}/indicators", ['skill_ids' => [$this->indicator->id, $standard->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['skill_ids.1']);
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$course->id}/indicators", ['skill_ids' => [$otherSchool->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['skill_ids.0']);
        $indicators = $this->asUser($this->teacher)->putJson("/api/v1/courses/{$course->id}/indicators", ['skill_ids' => [$this->indicator2->id, $ownSchool->id, $this->indicator->id]])
            ->assertOk()
            ->assertJsonCount(3, 'data.indicators')
            ->assertJsonPath('data.indicator_count', 3)
            ->json('data.indicators');
        $labels = array_column($indicators, 'source_label', 'id');
        $this->assertSame(['ครูเพิ่มเอง', null], [$labels[$ownSchool->id], $labels[$this->indicator->id]]);
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$course->id}/indicators", ['skill_ids' => []])->assertOk()->assertJsonPath('data.indicators', []);
    }

    public function test_units_and_lesson_plans_keep_their_order_and_indicators(): void
    {
        $course = $this->makeCourse($this->teacher);
        $u1 = $this->asUser($this->teacher)->postJson("/api/v1/courses/{$course->id}/units", ['title' => 'เศษส่วน', 'hours' => 12, 'skill_ids' => [$this->indicator->id]])
            ->assertCreated()->assertJsonPath('data.position', 1)->assertJsonPath('data.indicators.0.id', $this->indicator->id)->json('data.id');
        $u2 = $this->asUser($this->teacher)->postJson("/api/v1/courses/{$course->id}/units", ['title' => 'ทศนิยม'])->assertCreated()->assertJsonPath('data.position', 2)->json('data.id');
        // Inserted first: the others move down.
        $u0 = $this->asUser($this->teacher)->postJson("/api/v1/courses/{$course->id}/units", ['title' => 'ทบทวน', 'position' => 1])->assertCreated()->assertJsonPath('data.position', 1)->json('data.id');
        $this->assertSame([$u0, $u1, $u2], Unit::query()->where('course_id', $course->id)->orderBy('position')->pluck('id')->all());

        $this->asUser($this->teacher)->patchJson("/api/v1/units/{$u0}", ['position' => 3, 'title' => 'ทบทวนท้ายภาค'])->assertOk()->assertJsonPath('data.title', 'ทบทวนท้ายภาค');
        $this->assertSame([$u1, $u2, $u0], Unit::query()->where('course_id', $course->id)->orderBy('position')->pluck('id')->all());
        $this->asUser($this->teacher)->putJson("/api/v1/units/{$u2}/indicators", ['skill_ids' => [$this->indicator2->id]])->assertOk()->assertJsonPath('data.indicators.0.id', $this->indicator2->id);

        $p1 = $this->asUser($this->teacher)->postJson("/api/v1/courses/{$course->id}/lesson-plans", [
            'unit_id' => $u1, 'title' => 'การบวกเศษส่วน', 'hours' => 2, 'objectives' => 'บวกเศษส่วนได้',
            'content' => 'เศษส่วนที่ตัวส่วนเท่ากัน', 'activities' => 'ใช้แถบเศษส่วน', 'assessment' => 'ใบงาน',
            'skill_ids' => [$this->indicator->id],
        ])->assertCreated()
            ->assertJsonPath('data.unit_id', $u1)
            ->assertJsonPath('data.position', 1)
            ->assertJsonPath('data.taught_on', null)
            ->assertJsonPath('data.indicators.0.code', 'ค 1.1 ป.5/1')
            ->json('data.id');
        $p2 = $this->asUser($this->teacher)->postJson("/api/v1/courses/{$course->id}/lesson-plans", ['title' => 'แผนนอกหน่วย'])->assertCreated()->assertJsonPath('data.unit_id', null)->json('data.id');
        // A unit of another course is refused.
        $otherUnit = Unit::create(['course_id' => $this->makeCourse($this->teacher, [], ['code' => 'ค15102'])->id, 'position' => 1, 'title' => 'x']);
        $this->asUser($this->teacher)->postJson("/api/v1/courses/{$course->id}/lesson-plans", ['title' => 'x', 'unit_id' => $otherUnit->id])->assertStatus(422)->assertJsonValidationErrors(['unit_id']);

        // Mark it taught; the date comes back as sent.
        $this->asUser($this->teacher)->patchJson("/api/v1/lesson-plans/{$p1}", ['taught_on' => '2026-11-03'])->assertOk()->assertJsonPath('data.taught_on', '2026-11-03');
        $this->asUser($this->teacher)->patchJson("/api/v1/lesson-plans/{$p1}", ['taught_on' => '03/11/2026'])->assertStatus(422)->assertJsonValidationErrors(['taught_on']);
        $this->asUser($this->teacher)->putJson("/api/v1/lesson-plans/{$p1}/indicators", ['skill_ids' => [$this->indicator->id, $this->indicator2->id]])->assertOk()->assertJsonCount(2, 'data.indicators');
        $this->asUser($this->teacher)->getJson("/api/v1/lesson-plans/{$p1}")->assertOk()->assertJsonPath('data.objectives', 'บวกเศษส่วนได้');

        $detail = $this->asUser($this->teacher)->getJson("/api/v1/courses/{$course->id}")->assertOk();
        $this->assertSame([$u1, $u2, $u0], array_column($detail->json('data.units'), 'id'));
        $this->assertSame([$p1, $p2], array_column($detail->json('data.lesson_plans'), 'id'));
        $this->assertSame([3, 2], [$detail->json('data.unit_count'), $detail->json('data.lesson_plan_count')]);

        // Deleting a unit keeps its plans (outside any unit) and renumbers the units.
        $this->asUser($this->teacher)->deleteJson("/api/v1/units/{$u1}")->assertNoContent();
        $this->assertNull(LessonPlan::query()->findOrFail($p1)->unit_id);
        $this->assertSame([1, 2], Unit::query()->where('course_id', $course->id)->orderBy('position')->pluck('position')->all());
        $this->asUser($this->teacher)->deleteJson("/api/v1/lesson-plans/{$p1}")->assertNoContent();
        $this->assertSame(1, LessonPlan::query()->findOrFail($p2)->position);
    }

    public function test_only_the_creator_reaches_a_course_its_units_and_plans(): void
    {
        $course = $this->makeCourse($this->teacher);
        $unit = Unit::create(['course_id' => $course->id, 'position' => 1, 'title' => 'หน่วย']);
        $plan = LessonPlan::create(['course_id' => $course->id, 'position' => 1, 'title' => 'แผน']);

        foreach ([$this->makeTeacher($this->teacher->school), $this->makeTeacher()] as $other) {
            $this->asUser($other)->getJson('/api/v1/courses')->assertOk()->assertJsonCount(0, 'data');
            $this->asUser($other)->getJson("/api/v1/courses/{$course->id}")->assertNotFound();
            $this->asUser($other)->patchJson("/api/v1/courses/{$course->id}", ['name' => 'x'])->assertNotFound();
            $this->asUser($other)->deleteJson("/api/v1/courses/{$course->id}")->assertNotFound();
            $this->asUser($other)->postJson("/api/v1/courses/{$course->id}/units", ['title' => 'x'])->assertNotFound();
            $this->asUser($other)->patchJson("/api/v1/units/{$unit->id}", ['title' => 'x'])->assertNotFound();
            $this->asUser($other)->getJson("/api/v1/lesson-plans/{$plan->id}")->assertNotFound();
            $this->asUser($other)->patchJson("/api/v1/lesson-plans/{$plan->id}", ['taught_on' => '2026-11-03'])->assertNotFound();
        }
        ['student' => $student] = $this->enrollStudent($this->makeClassroom($this->teacher));
        $this->asUser($student, ['student'])->getJson('/api/v1/courses')->assertForbidden();

        $this->asUser($this->teacher)->patchJson("/api/v1/courses/{$course->id}", ['name' => 'คณิตศาสตร์ 5 (ปรับปรุง)', 'hours' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'คณิตศาสตร์ 5 (ปรับปรุง)')
            ->assertJsonPath('data.hours', null);
    }

    public function test_a_course_in_use_cannot_be_deleted_or_unbound_from_the_classroom_of_its_assignments(): void
    {
        $room = $this->makeClassroom($this->teacher);
        $other = $this->makeClassroom($this->teacher);
        $course = $this->makeCourse($this->teacher, [$room, $other]);
        Assignment::factory()->for_classroom($room)->create(['subject_id' => $course->subject_id, 'course_id' => $course->id]);

        $this->asUser($this->teacher)->deleteJson("/api/v1/courses/{$course->id}")->assertStatus(409)->assertJsonPath('code', 'course_in_use');
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$course->id}/classrooms", ['classroom_ids' => [$other->id]])
            ->assertStatus(409)->assertJsonPath('code', 'course_in_use');
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$course->id}/classrooms", ['classroom_ids' => [$room->id]])->assertOk();
        $science = Subject::factory()->create(['code' => 'ว']);
        $this->asUser($this->teacher)->patchJson("/api/v1/courses/{$course->id}", ['subject_id' => $science->id])->assertStatus(409)->assertJsonPath('code', 'course_in_use');

        $unused = $this->makeCourse($this->teacher, [$room], ['code' => 'ค15102']);
        LessonPlan::create(['course_id' => $unused->id, 'position' => 1, 'title' => 'แผน']);
        $this->asUser($this->teacher)->deleteJson("/api/v1/courses/{$unused->id}")->assertNoContent();
        $this->assertNull(Course::query()->find($unused->id));
        $this->assertSame(0, LessonPlan::query()->where('course_id', $unused->id)->count());
    }

    public function test_a_new_assignment_needs_a_course_of_its_classroom_and_may_link_a_lesson_plan(): void
    {
        $room = $this->makeClassroom($this->teacher);
        $otherRoom = $this->makeClassroom($this->teacher);
        $course = $this->makeCourse($this->teacher, [$room]);
        $plan = LessonPlan::create(['course_id' => $course->id, 'position' => 1, 'title' => 'การบวกเศษส่วน']);
        $elsewhere = $this->makeCourse($this->teacher, [$otherRoom], ['code' => 'ค15102']);
        $foreignPlan = LessonPlan::create(['course_id' => $elsewhere->id, 'position' => 1, 'title' => 'แผนของรายวิชาอื่น']);
        $colleagueCourse = $this->makeCourse($this->makeTeacher($this->teacher->school), [], ['code' => 'ค15101']);

        $body = fn (array $extra) => $extra + ['classroom_id' => $room->id, 'title' => 'เศษส่วน ชุดที่ 1'];
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $body([]))->assertStatus(422)->assertJsonValidationErrors(['course_id']);
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $body(['course_id' => $elsewhere->id]))->assertStatus(422)->assertJsonValidationErrors(['course_id']);
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $body(['course_id' => $colleagueCourse->id]))->assertStatus(422)->assertJsonValidationErrors(['course_id']);
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $body(['course_id' => $course->id, 'lesson_plan_id' => $foreignPlan->id]))->assertStatus(422)->assertJsonValidationErrors(['lesson_plan_id']);

        $science = Subject::factory()->create(['code' => 'ว']);
        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', $body(['course_id' => $course->id, 'lesson_plan_id' => $plan->id, 'subject_id' => $science->id]))
            ->assertCreated()
            ->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.course.code', 'ค15101')
            ->assertJsonPath('data.lesson_plan_id', $plan->id)
            ->assertJsonPath('data.lesson_plan.title', 'การบวกเศษส่วน')
            ->assertJsonPath('data.subject_id', $this->math->id) // from the course, not the body
            ->json('data.id');

        $this->asUser($this->teacher)->getJson("/api/v1/assignments?course_id={$course->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.course.id', $course->id);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments?lesson_plan_id={$plan->id}")->assertOk()->assertJsonCount(1, 'data');

        // PATCH: unlink the plan, then move to another course of the same classroom.
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['lesson_plan_id' => null])->assertOk()->assertJsonPath('data.lesson_plan_id', null);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['lesson_plan_id' => $plan->id])->assertOk()->assertJsonPath('data.lesson_plan_id', $plan->id);
        $second = $this->makeCourse($this->teacher, [$room], ['code' => 'ค15103']);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['course_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('data.course_id', $second->id)
            ->assertJsonPath('data.lesson_plan_id', null); // the plan belonged to the previous course
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['course_id' => $elsewhere->id])->assertStatus(422)->assertJsonValidationErrors(['course_id']);

        // A course of another subject is refused once the assignment has questions.
        $scienceCourse = $this->makeCourse($this->teacher, [$room], ['code' => 'ว15101', 'subject_id' => $science->id]);
        Question::factory()->create(['assignment_id' => $id]);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['course_id' => $scienceCourse->id])->assertStatus(422)->assertJsonValidationErrors(['course_id']);

        // Deleting the plan keeps the assignment in its course.
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['lesson_plan_id' => LessonPlan::create(['course_id' => $second->id, 'position' => 1, 'title' => 'แผน'])->id])->assertOk();
        LessonPlan::query()->where('course_id', $second->id)->delete();
        $this->assertSame([$second->id, null], [Assignment::query()->findOrFail($id)->course_id, Assignment::query()->findOrFail($id)->lesson_plan_id]);
    }

    public function test_an_older_assignment_without_a_course_may_take_one_when_its_key_is_approved(): void
    {
        $room = $this->makeClassroom($this->teacher);
        $assignment = Assignment::factory()->for_classroom($room)->create(['subject_id' => $this->math->id, 'mode' => Assignment::MODE_FREEFORM]);
        Question::factory()->create(['assignment_id' => $assignment->id]);
        $course = $this->makeCourse($this->teacher, [$room]);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$assignment->id}/answer-key/approve", ['course_id' => $course->id])->assertOk();
        $this->assertSame($course->id, $assignment->refresh()->course_id);

        // Without one it is still approved (only Classroom website mirrors must pick a course).
        $older = Assignment::factory()->for_classroom($room)->create(['subject_id' => $this->math->id, 'mode' => Assignment::MODE_FREEFORM]);
        Question::factory()->create(['assignment_id' => $older->id]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$older->id}/answer-key/approve")->assertOk();
        $this->assertNull($older->refresh()->course_id);
    }
}
