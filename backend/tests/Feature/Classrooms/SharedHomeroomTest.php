<?php

namespace Tests\Feature\Classrooms;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\PushMessage;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\ClassroomCourseRequest;
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
        $science = Subject::query()->create(['code' => 'ว', 'name' => 'วิทยาศาสตร์']);
        $this->subjectCourse = $this->makeCourse($this->subjectTeacher, [], ['code' => 'ว15101', 'name' => 'วิทยาศาสตร์ 5', 'subject_id' => $science->id]);
    }

    public function test_a_request_is_sent_approved_and_notified_both_ways(): void
    {
        $url = "/api/v1/classrooms/{$this->classroom->id}/course-requests";
        $created = $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => $this->subjectCourse->id, 'message' => ' ขอสอนวิทย์ '])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.origin', 'teacher')
            ->assertJsonPath('data.message', 'ขอสอนวิทย์')
            ->assertJsonPath('data.classroom.homeroom_teacher.id', $this->homeroom->id)
            ->assertJsonPath('data.course.code', 'ว15101')
            ->assertJsonPath('data.requester.id', $this->subjectTeacher->id)
            ->json('data');
        $this->assertFalse(ClassroomAccess::for($this->subjectTeacher, $this->classroom) !== null);

        $push = $this->notifier->ofType(PushMessage::COURSE_REQUEST);
        $this->assertCount(1, $push);
        $this->assertSame([$this->homeroom->id], $push[0][0]);
        $this->assertSame('มีคำขอผูกรายวิชา ว15101 กับห้อง ป.5/1', $push[0][1]->body);
        $this->assertSame(['type' => 'course_request', 'request_id' => (string) $created['id'], 'classroom_id' => (string) $this->classroom->id], $push[0][1]->data());

        // A second request of the pair waits for the first.
        $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => $this->subjectCourse->id])
            ->assertStatus(409)->assertJsonPath('code', 'request_pending');

        // Both boxes and the attention card.
        $this->asUser($this->homeroom)->getJson('/api/v1/course-requests?box=incoming')->assertOk()->assertJsonPath('data.0.id', $created['id']);
        $this->asUser($this->homeroom)->getJson('/api/v1/course-requests?box=outgoing')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->subjectTeacher)->getJson('/api/v1/course-requests?box=outgoing&status=pending')->assertOk()->assertJsonPath('data.0.id', $created['id']);
        $this->asUser($this->subjectTeacher)->getJson('/api/v1/course-requests?box=incoming')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->homeroom)->getJson('/api/v1/teacher/attention')->assertOk()->assertJsonPath('data.course_requests_pending', 1);

        // Only the homeroom teacher decides.
        $this->asUser($this->subjectTeacher)->postJson("/api/v1/course-requests/{$created['id']}/approve")
            ->assertStatus(403)->assertJsonPath('code', 'not_homeroom_teacher');
        $this->asUser($this->homeroom)->postJson("/api/v1/course-requests/{$created['id']}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_at', fn ($v) => $v !== null);
        $this->assertDatabaseHas('course_classroom', ['course_id' => $this->subjectCourse->id, 'classroom_id' => $this->classroom->id]);
        $this->assertSame(ClassroomAccess::SUBJECT, ClassroomAccess::for($this->subjectTeacher, $this->classroom)?->role);

        $decided = $this->notifier->ofType(PushMessage::COURSE_REQUEST_DECIDED);
        $this->assertSame([$this->subjectTeacher->id], $decided[0][0]);
        $this->assertStringContainsString('อนุมัติให้ผูกรายวิชา ว15101 กับห้อง ป.5/1', $decided[0][1]->body);

        // A decided request never changes again.
        foreach (['approve', 'decline'] as $action) {
            $this->asUser($this->homeroom)->postJson("/api/v1/course-requests/{$created['id']}/{$action}")
                ->assertStatus(409)->assertJsonPath('code', 'request_closed');
        }
        $this->asUser($this->subjectTeacher)->deleteJson("/api/v1/course-requests/{$created['id']}")
            ->assertStatus(409)->assertJsonPath('code', 'request_closed');
        $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => $this->subjectCourse->id])
            ->assertStatus(409)->assertJsonPath('code', 'course_already_in_classroom');
        $this->asUser($this->homeroom)->getJson('/api/v1/teacher/attention')->assertJsonPath('data.course_requests_pending', 0);
    }

    public function test_decline_and_cancel(): void
    {
        $url = "/api/v1/classrooms/{$this->classroom->id}/course-requests";
        $id = $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => $this->subjectCourse->id])->assertCreated()->json('data.id');
        $this->asUser($this->homeroom)->postJson("/api/v1/course-requests/{$id}/decline", ['reason' => 'ห้องนี้เรียนวิทย์กับครูอีกท่าน'])
            ->assertOk()->assertJsonPath('data.status', 'declined')->assertJsonPath('data.decline_reason', 'ห้องนี้เรียนวิทย์กับครูอีกท่าน');
        $this->assertDatabaseMissing('course_classroom', ['course_id' => $this->subjectCourse->id]);
        $this->assertStringContainsString('ไม่อนุมัติ', $this->notifier->ofType(PushMessage::COURSE_REQUEST_DECIDED)[0][1]->body);

        // Asking again after a decline is a new request; the requester takes it back.
        $again = $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => $this->subjectCourse->id])->assertCreated()->json('data.id');
        $this->asUser($this->homeroom)->deleteJson("/api/v1/course-requests/{$again}")->assertStatus(403)->assertJsonPath('code', 'not_requester');
        $this->asUser($this->subjectTeacher)->deleteJson("/api/v1/course-requests/{$again}")->assertNoContent();
        $this->assertSame(ClassroomCourseRequest::STATUS_CANCELLED, ClassroomCourseRequest::find($again)->status);
        $this->asUser($this->homeroom)->postJson("/api/v1/course-requests/{$again}/approve")->assertStatus(409)->assertJsonPath('code', 'request_closed');
        // Only the decision was pushed, not the cancel.
        $this->assertCount(1, $this->notifier->ofType(PushMessage::COURSE_REQUEST_DECIDED));
    }

    public function test_request_rules(): void
    {
        $url = "/api/v1/classrooms/{$this->classroom->id}/course-requests";
        // Another teacher's course, or a classroom of another school: 404.
        $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => $this->homeroomCourse->id])->assertNotFound();
        $stranger = $this->makeTeacher();
        $this->asUser($this->subjectTeacher)->postJson("/api/v1/classrooms/{$this->makeClassroom($stranger)->id}/course-requests", ['course_id' => $this->subjectCourse->id])->assertNotFound();
        $this->asUser($this->subjectTeacher)->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['course_id']);

        // The homeroom teacher's own course binds at once, without a request.
        $second = $this->makeCourse($this->homeroom, [], ['code' => 'ค15102']);
        $this->asUser($this->homeroom)->postJson($url, ['course_id' => $second->id])
            ->assertOk()->assertJsonPath('data.bound', true);
        $this->assertDatabaseHas('course_classroom', ['course_id' => $second->id, 'classroom_id' => $this->classroom->id]);
        $this->assertDatabaseCount('classroom_course_requests', 0);

        // Closing cancels what is pending, and a closed classroom takes no request.
        $id = $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => $this->subjectCourse->id])->assertCreated()->json('data.id');
        $this->asUser($this->homeroom)->postJson("/api/v1/classrooms/{$this->classroom->id}/close")->assertOk();
        $this->assertSame(ClassroomCourseRequest::STATUS_CANCELLED, ClassroomCourseRequest::find($id)->status);
        $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => $this->subjectCourse->id])
            ->assertStatus(409)->assertJsonPath('code', 'classroom_closed');
        $this->asUser($this->homeroom)->postJson("/api/v1/course-requests/{$id}/approve")->assertStatus(409)->assertJsonPath('code', 'request_closed');
    }

    public function test_the_directory_lists_open_classrooms_of_the_school_without_rosters(): void
    {
        $other = $this->makeClassroom($this->makeTeacher($this->homeroom->school), ['name' => 'ป.6/2', 'academic_year' => 2568]);
        $this->makeClassroom($this->homeroom, ['name' => 'ป.5/9', 'closed_at' => now()]);
        $this->makeClassroom($this->makeTeacher(), ['name' => 'ป.5/1 โรงเรียนอื่น']);

        $rows = $this->asUser($this->subjectTeacher)->getJson('/api/v1/classrooms/directory')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->classroom->id, $other->id], array_column($rows, 'id'));
        $row = collect($rows)->firstWhere('id', $this->classroom->id);
        $this->assertSame(['id', 'name', 'grade_level', 'academic_year', 'homeroom_teacher', 'students_count', 'my_role'], array_keys($row));
        $this->assertSame(2, $row['students_count']);
        $this->assertNull($row['my_role']);

        $this->assertSame([$other->id], array_column($this->asUser($this->subjectTeacher)->getJson('/api/v1/classrooms/directory?academic_year=2568')->json('data'), 'id'));
        $this->assertSame([$this->classroom->id], array_column($this->asUser($this->subjectTeacher)->getJson('/api/v1/classrooms/directory?q='.urlencode('ประจำชั้น'))->json('data'), 'id'));
        $this->assertSame([$this->classroom->id], array_column($this->asUser($this->subjectTeacher)->getJson('/api/v1/classrooms/directory?q=5/1')->json('data'), 'id'));
        $this->assertSame('homeroom', collect($this->asUser($this->homeroom)->getJson('/api/v1/classrooms/directory')->json('data'))->firstWhere('id', $this->classroom->id)['my_role']);
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
