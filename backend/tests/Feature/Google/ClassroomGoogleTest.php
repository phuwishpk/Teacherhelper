<?php

namespace Tests\Feature\Google;

use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST/DELETE /classrooms/{id}/google-link and GET/PUT
 * /classrooms/{id}/google-roster (DESIGN §18.6).
 */
class ClassroomGoogleTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    /** @var array<int, User> by student number */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGoogle();
        $this->teacher = $this->makeTeacher();
        $this->connectGoogle($this->teacher);
        $this->classroom = $this->makeClassroom($this->teacher);
        foreach ([1 => 'ด.ช. สมชาย ใจดี', 2 => 'ด.ญ. สมศรี มีสุข', 3 => 'Suda Jaidee', 4 => 'ด.ช. ปิติ ชูใจ'] as $n => $name) {
            $this->students[$n] = $this->enrollStudent($this->classroom, $n, $name)['student'];
        }
    }

    private function fakeCourses(): void
    {
        $this->fakeGoogle([
            'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/students*' => Http::response(['students' => [
                ['userId' => 'g-somchai', 'profile' => ['id' => 'g-somchai', 'name' => ['fullName' => 'สมชาย ใจดี'], 'emailAddress' => 'somchai@student.example']],
                ['userId' => 'g-somsri', 'profile' => ['id' => 'g-somsri', 'name' => ['fullName' => 'เด็กหญิงสมศรี มีสุข'], 'emailAddress' => 'somsri@student.example']],
                ['userId' => 'g-suda', 'profile' => ['id' => 'g-suda', 'name' => ['fullName' => 'Jaidee Suda']]],
                ['userId' => 'g-stranger', 'profile' => ['id' => 'g-stranger', 'name' => ['fullName' => 'Somebody Else'], 'emailAddress' => 'x@student.example']],
            ]]),
            'classroom.googleapis.com/v1/courses*' => Http::response(['courses' => [
                ['id' => self::COURSE_ID, 'name' => 'คณิตศาสตร์ ม.1/1', 'section' => '1/1'],
                ['id' => 'other-course', 'name' => 'วิทย์ ม.1/1'],
            ]]),
        ]);
    }

    private function link(?Classroom $classroom = null, string $courseId = self::COURSE_ID): ClassroomGoogleLink
    {
        return ClassroomGoogleLink::create([
            'classroom_id' => ($classroom ?? $this->classroom)->id,
            'course_id' => $courseId,
            'course_name' => 'คณิตศาสตร์ ม.1/1',
            'owner_user_id' => $this->teacher->id,
            'linked_at' => now(),
        ]);
    }

    public function test_linking_needs_a_course_the_teacher_teaches_and_shows_on_the_classroom(): void
    {
        $this->fakeCourses();

        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/google-link", ['course_id' => 'not-mine'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['course_id']]);

        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/google-link", ['course_id' => self::COURSE_ID])
            ->assertCreated()
            ->assertJsonPath('data.course_id', self::COURSE_ID)
            ->assertJsonPath('data.course_name', 'คณิตศาสตร์ ม.1/1');
        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/google-link", ['course_id' => self::COURSE_ID])
            ->assertOk();

        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}")
            ->assertJsonPath('data.google_link.course_id', self::COURSE_ID)
            ->assertJsonPath('data.google_link.course_name', 'คณิตศาสตร์ ม.1/1');
        $this->asUser($this->teacher)->getJson('/api/v1/classrooms')
            ->assertJsonPath('data.0.google_link.course_id', self::COURSE_ID);

        $other = $this->makeClassroom($this->teacher);
        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$other->id}")->assertJsonPath('data.google_link', null);
        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$other->id}/google-link", ['course_id' => self::COURSE_ID])
            ->assertStatus(409)
            ->assertJsonPath('code', 'course_already_linked');
    }

    public function test_a_room_with_posted_assignments_cannot_move_to_another_course_but_can_unlink(): void
    {
        $this->fakeCourses();
        $this->link();
        $assignment = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_READY]);
        AssignmentGoogleLink::create(['assignment_id' => $assignment->id, 'course_work_id' => 'cw', 'alternate_link' => 'https://classroom.google.com/x', 'posted_by' => $this->teacher->id, 'posted_at' => now()]);

        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/google-link", ['course_id' => 'other-course'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'classroom_has_google_posts');

        $this->asUser($this->teacher)->deleteJson("/api/v1/classrooms/{$this->classroom->id}/google-link")->assertNoContent();
        $this->assertDatabaseCount('classroom_google_links', 0);
        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/google-roster")
            ->assertStatus(422)
            ->assertJsonPath('code', 'classroom_not_linked');
    }

    public function test_the_roster_suggests_pairs_by_normalised_name(): void
    {
        $this->fakeCourses();
        $this->link();

        $res = $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/google-roster")
            ->assertOk()
            ->assertJsonPath('meta.course_id', self::COURSE_ID);
        $rows = collect($res->json('data'))->keyBy('google_user_id');

        $this->assertSame($this->students[1]->id, $rows['g-somchai']['suggested_student_id']);
        $this->assertSame($this->students[2]->id, $rows['g-somsri']['suggested_student_id']);
        $this->assertSame($this->students[3]->id, $rows['g-suda']['suggested_student_id'], 'surname first');
        $this->assertNull($rows['g-stranger']['suggested_student_id']);
        $this->assertNull($rows['g-somchai']['matched_student_id']);
        $this->assertSame('somchai@student.example', $rows['g-somchai']['email']);
        $this->assertNull($rows['g-suda']['email']);
        $this->assertStringContainsString('pageSize=100', $this->sentTo('/students')[0]->url());
    }

    public function test_saving_matches_one_student_per_account_and_follows_moves(): void
    {
        $this->fakeCourses();
        $this->link();
        $url = "/api/v1/classrooms/{$this->classroom->id}/google-roster";

        $this->asUser($this->teacher)->putJson($url, ['matches' => [
            ['google_user_id' => 'g-somchai', 'student_id' => $this->students[1]->id],
            ['google_user_id' => 'g-somsri', 'student_id' => $this->students[2]->id],
            ['google_user_id' => 'g-stranger', 'student_id' => null],
        ]])->assertOk()
            ->assertJsonFragment(['google_user_id' => 'g-somchai', 'matched_student_id' => $this->students[1]->id]);

        $pivot = fn (int $n) => ClassroomStudent::query()->where('classroom_id', $this->classroom->id)->where('student_id', $this->students[$n]->id)->first();
        $this->assertSame(['g-somchai', 'somchai@student.example'], [$pivot(1)->google_user_id, $pivot(1)->google_email]);

        // The same student for two accounts is refused.
        $this->asUser($this->teacher)->putJson($url, ['matches' => [
            ['google_user_id' => 'g-somchai', 'student_id' => $this->students[4]->id],
            ['google_user_id' => 'g-suda', 'student_id' => $this->students[4]->id],
        ]])->assertStatus(422)->assertJsonStructure(['errors' => ['matches.1.student_id']]);

        // Moving an account to another student frees the first one; the move of
        // student 2's account to student 1 does not trip the unique key.
        $this->asUser($this->teacher)->putJson($url, ['matches' => [
            ['google_user_id' => 'g-somsri', 'student_id' => $this->students[1]->id],
            ['google_user_id' => 'g-somchai', 'student_id' => $this->students[2]->id],
        ]])->assertOk();
        $this->assertSame(['g-somsri', 'g-somchai'], [$pivot(1)->google_user_id, $pivot(2)->google_user_id]);

        $this->asUser($this->teacher)->putJson($url, ['matches' => [['google_user_id' => 'g-somsri', 'student_id' => null]]])->assertOk();
        $this->assertNull($pivot(1)->google_user_id);
        $this->assertSame('g-somchai', $pivot(2)->google_user_id, 'accounts not in the request keep their match');

        $rows = collect($this->asUser($this->teacher)->getJson($url)->json('data'))->keyBy('google_user_id');
        $this->assertSame($this->students[2]->id, $rows['g-somchai']['matched_student_id']);
        // Student 2 (สมศรี) is taken by g-somchai, so g-somsri gets no guess.
        $this->assertNull($rows['g-somsri']['suggested_student_id']);
        $this->assertNull($rows['g-somsri']['matched_student_id']);
    }

    public function test_matches_must_name_course_accounts_and_classroom_students(): void
    {
        $this->fakeCourses();
        $this->link();
        $outsider = $this->enrollStudent($this->makeClassroom($this->teacher), 1)['student'];

        $this->asUser($this->teacher)->putJson("/api/v1/classrooms/{$this->classroom->id}/google-roster", ['matches' => [
            ['google_user_id' => 'g-unknown', 'student_id' => $this->students[1]->id],
            ['google_user_id' => 'g-somchai', 'student_id' => $outsider->id],
        ]])->assertStatus(422)->assertJsonStructure(['errors' => ['matches.0.google_user_id', 'matches.1.student_id']]);
        $this->assertSame(0, ClassroomStudent::query()->whereNotNull('google_user_id')->count());
    }

    public function test_rematching_moves_unscanned_submissions_to_the_new_student(): void
    {
        $this->fakeCourses();
        $this->link();
        $assignment = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_READY]);
        $row = fn (string $id, string $state) => ClassroomSubmissionImport::create([
            'assignment_id' => $assignment->id, 'google_submission_id' => $id, 'google_user_id' => 'g-somchai',
            'student_id' => $this->students[4]->id, 'state' => $state, 'attachments' => [], 'google_update_time' => 't',
        ]);
        $fresh = $row('s-new', ClassroomSubmissionImport::STATE_NEW);
        $scanned = $row('s-imported', ClassroomSubmissionImport::STATE_IMPORTED);

        $this->asUser($this->teacher)->putJson("/api/v1/classrooms/{$this->classroom->id}/google-roster", ['matches' => [
            ['google_user_id' => 'g-somchai', 'student_id' => $this->students[1]->id],
        ]])->assertOk();

        $this->assertSame($this->students[1]->id, $fresh->refresh()->student_id);
        $this->assertSame($this->students[4]->id, $scanned->refresh()->student_id, 'the scans decide once imported');
    }

    public function test_only_the_classrooms_teacher_reaches_the_google_endpoints(): void
    {
        $this->fakeCourses();
        $this->link();
        $stranger = $this->makeTeacher($this->teacher->school);
        $this->connectGoogle($stranger);

        $this->asUser($stranger)->getJson("/api/v1/classrooms/{$this->classroom->id}/google-roster")->assertNotFound();
        $this->asUser($stranger)->postJson("/api/v1/classrooms/{$this->classroom->id}/google-link", ['course_id' => self::COURSE_ID])->assertNotFound();
        $this->asUser($stranger)->deleteJson("/api/v1/classrooms/{$this->classroom->id}/google-link")->assertNotFound();
        $this->asUser($this->students[1])->getJson("/api/v1/classrooms/{$this->classroom->id}/google-roster")->assertForbidden();
        $this->assertDatabaseCount('classroom_google_links', 1);
        $this->assertCount(0, $this->sentTo('/students'));
    }
}
