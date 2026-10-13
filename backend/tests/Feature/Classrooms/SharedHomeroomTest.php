<?php

namespace Tests\Feature\Classrooms;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\PushMessage;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Mastery;
use App\Models\Question;
use App\Models\Response;
use App\Models\Skill;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * Shared homerooms (DESIGN §24.7, §24.8): a subject teacher asks to bind a
 * course to another teacher's classroom, the homeroom teacher decides, and
 * then the subject teacher works in the classroom on their own course only
 * while the homeroom teacher reads every course of the class.
 */
class SharedHomeroomTest extends TestCase
{
    use RefreshDatabase;

    private User $homeroom;

    private User $subjectTeacher;

    private Classroom $classroom;

    private Course $homeroomCourse;

    private Course $subjectCourse;

    /** @var list<User> */
    private array $students;

    private RecordingNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifier = new RecordingNotifier;
        $this->app->instance(Notifier::class, $this->notifier);

        $this->homeroom = $this->makeTeacher(null, ['name' => 'ครูประจำชั้น']);
        $this->subjectTeacher = $this->makeTeacher($this->homeroom->school, ['name' => 'ครูวิทย์']);
        $this->classroom = $this->makeClassroom($this->homeroom, ['name' => 'ป.5/1', 'grade_level' => 5]);
        $this->students = [
            $this->enrollStudent($this->classroom, 1, 'นักเรียน หนึ่ง')['student'],
            $this->enrollStudent($this->classroom, 2, 'นักเรียน สอง')['student'],
        ];
        $this->homeroomCourse = $this->makeCourse($this->homeroom, [$this->classroom], ['code' => 'ค15101']);
        $science = Subject::query()->updateOrCreate(['code' => 'ว'], ['name' => 'วิทยาศาสตร์']);
        $this->subjectCourse = $this->makeCourse($this->subjectTeacher, [], ['code' => 'ว15101', 'name' => 'วิทยาศาสตร์ 5', 'subject_id' => $science->id]);
    }

    public function test_the_subject_teacher_works_in_the_classroom_on_their_own_course_only(): void
    {
        $this->bind();

        $list = $this->asUser($this->subjectTeacher)->getJson('/api/v1/classrooms')->assertOk()->json('data');
        $this->assertSame([$this->classroom->id], array_column($list, 'id'));
        $this->assertSame('subject', $list[0]['my_role']);
        $this->assertSame($this->homeroom->id, $list[0]['homeroom_teacher']['id']);
        $this->assertSame('homeroom', $this->asUser($this->homeroom)->getJson('/api/v1/classrooms')->json('data.0.my_role'));

        // The roster reads without the PIN and Google state.
        $roster = $this->asUser($this->subjectTeacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/roster")->assertOk()->json('data');
        $this->assertSame(['นักเรียน หนึ่ง', 'นักเรียน สอง'], array_column($roster, 'name'));
        $this->assertNull($roster[0]['pin_pending']);
        $this->assertNull($roster[0]['left_course_at']);
        $this->assertFalse($this->asUser($this->homeroom)->getJson("/api/v1/classrooms/{$this->classroom->id}/roster")->json('data.0.pin_pending'));

        // Homework and exams of the own course only.
        $create = fn (Course $course, array $extra = []) => $this->asUser($this->subjectTeacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $course->id, 'title' => 'งานวิทย์',
        ] + $extra);
        $mine = $create($this->subjectCourse)->assertCreated()->assertJsonPath('data.can_manage', true)->json('data.id');
        $create($this->subjectCourse, ['kind' => 'exam', 'due_at' => now()->addWeek()->toIso8601String()])->assertCreated();
        $create($this->homeroomCourse)->assertStatus(422)->assertJsonValidationErrors(['course_id']);
        $this->assertSame($this->subjectTeacher->id, Assignment::find($mine)->created_by);

        // The homeroom teacher cannot put work under the subject teacher's course.
        $this->asUser($this->homeroom)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->subjectCourse->id, 'title' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['course_id']);

        $theirs = Assignment::factory()->for_classroom($this->classroom)->create(['course_id' => $this->homeroomCourse->id, 'subject_id' => $this->homeroomCourse->subject_id]);
        $legacy = Assignment::factory()->for_classroom($this->classroom)->create();

        $seen = array_column($this->asUser($this->subjectTeacher)->getJson('/api/v1/assignments')->json('data'), 'id');
        $this->assertNotContains($theirs->id, $seen);
        $this->assertNotContains($legacy->id, $seen);
        $this->assertContains($mine, $seen);
        $this->asUser($this->subjectTeacher)->getJson("/api/v1/assignments/{$theirs->id}")->assertNotFound();

        // The homeroom teacher sees every course of the class, the subject teacher's read-only.
        $rows = collect($this->asUser($this->homeroom)->getJson("/api/v1/assignments?classroom_id={$this->classroom->id}")->json('data'))->keyBy('id');
        $this->assertTrue($rows[$theirs->id]['can_manage']);
        $this->assertTrue($rows[$legacy->id]['can_manage']);
        $this->assertFalse($rows[$mine]['can_manage']);
        $this->asUser($this->homeroom)->getJson("/api/v1/assignments/{$mine}")->assertOk();
        $this->asUser($this->homeroom)->patchJson("/api/v1/assignments/{$mine}", ['title' => 'แก้'])
            ->assertStatus(403)->assertJsonPath('code', 'not_course_teacher');

        // The courses of the classroom: all for the homeroom teacher, the own one for the subject teacher.
        $all = $this->asUser($this->homeroom)->getJson("/api/v1/classrooms/{$this->classroom->id}/courses")->assertOk()->json('data');
        $this->assertSame(['ค15101', 'ว15101'], array_column(array_column($all, 'course'), 'code'));
        $this->assertSame([true, false], array_column($all, 'is_mine'));
        $this->assertSame('ครูวิทย์', $all[1]['teacher']['name']);
        $own = $this->asUser($this->subjectTeacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/courses")->assertOk()->json('data');
        $this->assertSame(['ว15101'], array_column(array_column($own, 'course'), 'code'));

        // No roster or classroom edits for the subject teacher.
        $this->asUser($this->subjectTeacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/students", ['students' => [['name' => 'ใหม่', 'student_number' => 3]]])
            ->assertStatus(403)->assertJsonPath('code', 'not_homeroom_teacher');
        $this->asUser($this->subjectTeacher)->patchJson("/api/v1/students/{$this->students[0]->id}", ['name' => 'แก้ชื่อ'])
            ->assertStatus(403)->assertJsonPath('code', 'not_homeroom_teacher');
        $this->asUser($this->subjectTeacher)->postJson("/api/v1/students/{$this->students[0]->id}/pin")->assertForbidden();
    }

    public function test_results_charts_and_mastery_follow_the_course(): void
    {
        $this->bind();
        $science = Skill::factory()->create(['subject_id' => $this->subjectCourse->subject_id, 'code' => 'ว 1.1 ป.5/1']);
        $math = Skill::factory()->create(['subject_id' => $this->homeroomCourse->subject_id, 'code' => 'ค 1.1 ป.5/1']);
        $this->subjectCourse->indicators()->attach($science->id);
        $this->homeroomCourse->indicators()->attach($math->id);
        foreach ([$science, $math] as $skill) {
            Mastery::create(['student_id' => $this->students[0]->id, 'skill_id' => $skill->id, 'value' => 0.8, 'n_obs' => 3]);
        }

        // The heatmap needs an own course for the subject teacher and shows its indicators only.
        $heat = "/api/v1/classrooms/{$this->classroom->id}/mastery";
        $this->asUser($this->subjectTeacher)->getJson($heat)->assertStatus(422)->assertJsonValidationErrors(['course_id']);
        $this->asUser($this->subjectTeacher)->getJson("{$heat}?course_id={$this->homeroomCourse->id}")->assertStatus(422);
        $skills = $this->asUser($this->subjectTeacher)->getJson("{$heat}?course_id={$this->subjectCourse->id}")->assertOk()->json('data.skills');
        $this->assertSame([$science->id], array_column($skills, 'id'));
        // The homeroom teacher sees every course, with or without a filter.
        $this->assertEqualsCanonicalizing([$science->id, $math->id], array_column($this->asUser($this->homeroom)->getJson($heat)->assertOk()->json('data.skills'), 'id'));
        $this->assertSame([$science->id], array_column($this->asUser($this->homeroom)->getJson("{$heat}?course_id={$this->subjectCourse->id}")->assertOk()->json('data.skills'), 'id'));
        $this->asUser($this->subjectTeacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/indicator-pass-rate?course_id={$this->subjectCourse->id}")->assertOk();

        // One student's mastery and progress lines.
        $student = "/api/v1/students/{$this->students[0]->id}";
        $this->asUser($this->subjectTeacher)->getJson("{$student}/mastery")->assertStatus(422)->assertJsonValidationErrors(['course_id']);
        $this->assertSame([$science->id], array_column($this->asUser($this->subjectTeacher)->getJson("{$student}/mastery?course_id={$this->subjectCourse->id}")->assertOk()->json('data'), 'skill_id'));
        $this->assertCount(2, $this->asUser($this->homeroom)->getJson("{$student}/mastery")->assertOk()->json('data'));
        $this->assertSame([$science->id], collect($this->asUser($this->subjectTeacher)->getJson("{$student}/indicator-progress?course_id={$this->subjectCourse->id}")->assertOk()->json('data.skills'))->pluck('skill.id')->all());
        $this->asUser($this->subjectTeacher)->getJson("{$student}/indicator-progress?course_id={$this->subjectCourse->id}&skill_ids={$math->id}")->assertStatus(422);
        $this->asUser($this->subjectTeacher)->getJson("{$student}/analysis?classroom_id={$this->classroom->id}")->assertStatus(403)->assertJsonPath('code', 'not_homeroom_teacher');

        // A student outside the subject teacher's classrooms stays invisible.
        $elsewhere = $this->enrollStudent($this->makeClassroom($this->homeroom), 1, 'ห้องอื่น')['student'];
        $this->asUser($this->subjectTeacher)->getJson("/api/v1/students/{$elsewhere->id}/mastery?course_id={$this->subjectCourse->id}")->assertNotFound();
    }

    public function test_the_gradebook_of_a_course_is_its_teachers_and_the_homeroom_teacher_reads_it(): void
    {
        $this->bind();
        $this->asUser($this->subjectTeacher)->putJson("/api/v1/courses/{$this->subjectCourse->id}/gradebook/categories", ['template' => 'collect_final'])->assertOk();
        $grid = "/api/v1/courses/{$this->subjectCourse->id}/gradebook?classroom_id={$this->classroom->id}";

        $this->asUser($this->subjectTeacher)->getJson($grid)->assertOk();
        $overview = $this->asUser($this->subjectTeacher)->getJson('/api/v1/gradebook/overview')->assertOk()->json('data.courses');
        $this->assertSame([$this->classroom->id], array_column($overview[0]['classrooms'], 'id'));

        // The homeroom teacher reads the grid and the CSV, nothing more.
        $this->asUser($this->homeroom)->getJson($grid)->assertOk();
        $this->asUser($this->homeroom)->get("/api/v1/courses/{$this->subjectCourse->id}/gradebook/export?classroom_id={$this->classroom->id}")->assertOk();
        $this->asUser($this->homeroom)->getJson("/api/v1/courses/{$this->subjectCourse->id}/gradebook?classroom_id={$this->makeClassroom($this->homeroom)->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);
        $this->asUser($this->homeroom)->postJson("/api/v1/courses/{$this->subjectCourse->id}/gradebook/publish", ['classroom_id' => $this->classroom->id])->assertNotFound();
        $this->asUser($this->homeroom)->getJson("/api/v1/courses/{$this->subjectCourse->id}/gradebook/settings")->assertNotFound();

        // A colleague with no role in the classroom reads nothing.
        $this->asUser($this->makeTeacher($this->homeroom->school))->getJson($grid)->assertNotFound();
    }

    public function test_appeals_pushes_and_the_gemini_key_belong_to_the_courses_teacher(): void
    {
        $this->bind();
        $assignment = Assignment::factory()->for_classroom($this->classroom)->create([
            'course_id' => $this->subjectCourse->id, 'subject_id' => $this->subjectCourse->subject_id, 'created_by' => $this->subjectTeacher->id,
        ]);
        $this->assertSame($this->subjectTeacher->id, ClassroomAccess::managerId($assignment));
        $this->assertSame($this->homeroom->id, ClassroomAccess::managerId(Assignment::factory()->for_classroom($this->classroom)->create()));

        app(Notifier::class)->gradingFinished($assignment, 2, 0);
        $this->assertSame([$this->subjectTeacher->id], $this->notifier->ofType(PushMessage::GRADING_DONE)[0][0]);

        $question = Question::factory()->short()->create(['assignment_id' => $assignment->id, 'position' => 1]);
        $submission = Submission::create(['assignment_id' => $assignment->id, 'student_id' => $this->students[0]->id, 'status' => Submission::STATUS_PUBLISHED, 'published_at' => now()]);
        $response = Response::create(['submission_id' => $submission->id, 'question_id' => $question->id, 'grading_state' => Response::STATE_SCORED, 'final_score' => 1]);
        $this->asUser($this->students[0])->postJson("/api/v1/student/responses/{$response->id}/appeal", ['reason' => 'ขอตรวจใหม่'])->assertCreated();

        $this->assertCount(1, $this->asUser($this->subjectTeacher)->getJson('/api/v1/appeals')->assertOk()->json('data'));
        $this->assertCount(0, $this->asUser($this->homeroom)->getJson('/api/v1/appeals')->assertOk()->json('data'));
        $this->assertSame([$this->subjectTeacher->id], $this->notifier->ofType(PushMessage::APPEAL_OPENED)[0][0]);
    }

    public function test_unbinding_and_course_edits_keep_other_teachers_bindings(): void
    {
        $this->bind();
        $unbind = "/api/v1/classrooms/{$this->classroom->id}/courses/{$this->subjectCourse->id}";

        // PUT /courses/{id}/classrooms manages the teacher's own homerooms; the binding to classroom stays.
        $ownRoom = $this->makeClassroom($this->subjectTeacher);
        $this->asUser($this->subjectTeacher)->putJson("/api/v1/courses/{$this->subjectCourse->id}/classrooms", ['classroom_ids' => [$ownRoom->id]])->assertOk();
        $this->assertEqualsCanonicalizing([$this->classroom->id, $ownRoom->id], $this->subjectCourse->classrooms()->pluck('classrooms.id')->all());
        $this->asUser($this->subjectTeacher)->putJson("/api/v1/courses/{$this->subjectCourse->id}/classrooms", ['classroom_ids' => [$this->classroom->id]])
            ->assertStatus(422);

        // In use: 409; a colleague: 404; the subject teacher cannot unbind the homeroom teacher's course.
        $work = Assignment::factory()->for_classroom($this->classroom)->create(['course_id' => $this->subjectCourse->id, 'subject_id' => $this->subjectCourse->subject_id]);
        $this->asUser($this->homeroom)->deleteJson($unbind)->assertStatus(409)->assertJsonPath('code', 'course_in_use');
        $this->asUser($this->makeTeacher($this->homeroom->school))->deleteJson($unbind)->assertNotFound();
        $this->asUser($this->subjectTeacher)->deleteJson("/api/v1/classrooms/{$this->classroom->id}/courses/{$this->homeroomCourse->id}")->assertNotFound();
        $work->delete();

        $this->asUser($this->subjectTeacher)->deleteJson($unbind)->assertNoContent();
        $this->assertNull(ClassroomAccess::for($this->subjectTeacher, $this->classroom));
        $this->asUser($this->subjectTeacher)->getJson("/api/v1/classrooms/{$this->classroom->id}")->assertNotFound();

        $this->bind();
        $this->asUser($this->homeroom)->deleteJson($unbind)->assertNoContent();
        $this->assertDatabaseMissing('course_classroom', ['course_id' => $this->subjectCourse->id, 'classroom_id' => $this->classroom->id]);
    }

    public function test_a_closed_classroom_stays_readable_for_its_subject_teacher(): void
    {
        $this->bind();
        $work = Assignment::factory()->for_classroom($this->classroom)->create(['course_id' => $this->subjectCourse->id, 'subject_id' => $this->subjectCourse->subject_id]);
        $this->classroom->forceFill(['closed_at' => now()])->save();

        $this->asUser($this->subjectTeacher)->getJson("/api/v1/assignments/{$work->id}")->assertOk();
        $this->asUser($this->subjectTeacher)->patchJson("/api/v1/assignments/{$work->id}", ['title' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'classroom_closed');
        $this->asUser($this->subjectTeacher)->getJson('/api/v1/classrooms?state=closed')->assertOk()->assertJsonPath('data.0.id', $this->classroom->id);
    }

    private function bind(): void
    {
        $this->subjectCourse->classrooms()->syncWithoutDetaching([$this->classroom->id]);
    }
}
