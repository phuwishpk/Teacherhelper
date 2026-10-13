<?php

namespace Tests\Feature\Classrooms;

use App\Models\Classroom;
use App\Models\ClassroomTeacher;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §29.13.4: the owner invites a colleague (from another workspace) by
 * e-mail; the colleague accepts, becomes a subject teacher of the classroom
 * and binds their own course to it.
 */
class CoTeachingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $colleague;

    private Classroom $classroom;

    private Course $colleagueCourse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeTeacher(null, ['name' => 'ครูประจำชั้น', 'email' => 'owner@example.com']);
        $this->colleague = $this->makeTeacher(null, ['name' => 'ครูวิทย์', 'email' => 'Science.Teacher@example.com']);
        $this->classroom = $this->makeClassroom($this->owner, ['name' => 'ป.5/1', 'grade_level' => 5]);
        $this->enrollStudent($this->classroom, 1, 'นักเรียน หนึ่ง');
        $this->colleagueCourse = $this->makeCourse($this->colleague, [], ['code' => 'ว15101', 'name' => 'วิทยาศาสตร์ 5']);
    }

    private function invite(string $email = 'science.teacher@example.com'): array
    {
        return $this->asUser($this->owner)->postJson("/api/v1/classrooms/{$this->classroom->id}/teachers", ['email' => $email])
            ->assertCreated()->json('data');
    }

    public function test_the_owner_invites_by_email_and_the_colleague_accepts_and_teaches(): void
    {
        $url = "/api/v1/classrooms/{$this->classroom->id}/teachers";
        $this->asUser($this->owner)->postJson($url, ['email' => 'owner@example.com'])->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->asUser($this->owner)->postJson($url, ['email' => 'not-an-email'])->assertStatus(422);

        $row = $this->invite(' Science.Teacher@example.com ');
        $this->assertSame(['status' => 'pending', 'email' => 'science.teacher@example.com', 'teacher' => null, 'courses' => []], [
            'status' => $row['status'], 'email' => $row['email'], 'teacher' => $row['teacher'], 'courses' => $row['courses'],
        ]);
        $this->asUser($this->owner)->postJson($url, ['email' => 'SCIENCE.teacher@example.com'])->assertStatus(409)->assertJsonPath('code', 'invitation_exists');
        $this->asUser($this->owner)->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $row['id']);

        // The colleague sees it, nobody else does; the classroom is still invisible to them.
        $this->asUser($this->colleague)->getJson('/api/v1/classroom-invitations')->assertOk()
            ->assertJsonPath('data.0.id', $row['id'])
            ->assertJsonPath('data.0.classroom.name', 'ป.5/1')
            ->assertJsonPath('data.0.owner.name', 'ครูประจำชั้น');
        $this->asUser($this->makeTeacher())->getJson('/api/v1/classroom-invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->colleague)->getJson("/api/v1/classrooms/{$this->classroom->id}")->assertNotFound();
        $this->asUser($this->makeTeacher())->postJson("/api/v1/classroom-invitations/{$row['id']}/accept")->assertNotFound();

        $this->asUser($this->colleague)->postJson("/api/v1/classroom-invitations/{$row['id']}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('classroom_teachers', ['id' => $row['id'], 'teacher_id' => $this->colleague->id, 'status' => 'accepted']);
        $this->asUser($this->colleague)->getJson('/api/v1/classroom-invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->colleague)->postJson("/api/v1/classroom-invitations/{$row['id']}/accept")->assertNotFound();

        // A subject teacher of the classroom now: it is listed, the roster is readable, the students are not theirs to edit.
        $this->asUser($this->colleague)->getJson('/api/v1/classrooms')->assertOk()
            ->assertJsonPath('data.0.id', $this->classroom->id)->assertJsonPath('data.0.my_role', 'subject')
            ->assertJsonPath('data.0.homeroom_teacher.name', 'ครูประจำชั้น');
        $this->asUser($this->colleague)->getJson("/api/v1/classrooms/{$this->classroom->id}/roster")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.username', null);
        $this->asUser($this->colleague)->postJson("/api/v1/classrooms/{$this->classroom->id}/students", ['students' => [['name' => 'x', 'student_number' => 9]]])
            ->assertForbidden()->assertJsonPath('code', 'not_homeroom_teacher');
        $this->asUser($this->colleague)->getJson($url)->assertForbidden()->assertJsonPath('code', 'not_homeroom_teacher');

        // They bind their own course themselves and give homework in it.
        $this->asUser($this->colleague)->putJson("/api/v1/courses/{$this->colleagueCourse->id}/classrooms", ['classroom_ids' => [$this->classroom->id]])->assertOk();
        $this->asUser($this->owner)->getJson("/api/v1/classrooms/{$this->classroom->id}/courses")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.course.code', 'ว15101')->assertJsonPath('data.0.is_mine', false);
        $this->asUser($this->owner)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.teacher.name', 'ครูวิทย์')->assertJsonPath('data.0.courses.0.code', 'ว15101');
        $this->asUser($this->colleague)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->colleagueCourse->id, 'title' => 'งานวิทย์',
        ])->assertCreated();
        // The owner's timetable shows the colleague's periods as busy (§29.12).
        $this->asUser($this->colleague)->postJson("/api/v1/courses/{$this->colleagueCourse->id}/timetable-slots", [
            'classroom_id' => $this->classroom->id, 'weekday' => 2, 'period_no' => 3,
        ])->assertCreated();
        $this->asUser($this->owner)->getJson('/api/v1/timetable')->assertOk()->assertJsonCount(1, 'data.busy');
    }

    public function test_declined_invitations_can_be_renewed_and_closed_classrooms_take_nobody(): void
    {
        $row = $this->invite();
        $this->asUser($this->colleague)->postJson("/api/v1/classroom-invitations/{$row['id']}/decline")->assertOk()->assertJsonPath('data.status', 'declined');
        $this->asUser($this->colleague)->getJson('/api/v1/classroom-invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->owner)->getJson("/api/v1/classrooms/{$this->classroom->id}/teachers")->assertOk()->assertJsonPath('data.0.status', 'declined');

        // Asked again: the same row is pending once more.
        $again = $this->invite();
        $this->assertSame($row['id'], $again['id']);
        $this->assertSame('pending', $again['status']);

        $this->classroom->forceFill(['closed_at' => now()])->save();
        $this->asUser($this->colleague)->getJson('/api/v1/classroom-invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->colleague)->postJson("/api/v1/classroom-invitations/{$row['id']}/accept")->assertStatus(409)->assertJsonPath('code', 'classroom_closed');
        $this->asUser($this->owner)->postJson("/api/v1/classrooms/{$this->classroom->id}/teachers", ['email' => 'x@example.com'])->assertStatus(409)->assertJsonPath('code', 'classroom_closed');
    }

    public function test_the_owner_removes_a_co_teacher_once_their_courses_have_no_work_here(): void
    {
        $row = $this->invite();
        $this->asUser($this->colleague)->postJson("/api/v1/classroom-invitations/{$row['id']}/accept")->assertOk();
        $this->asUser($this->colleague)->putJson("/api/v1/courses/{$this->colleagueCourse->id}/classrooms", ['classroom_ids' => [$this->classroom->id]])->assertOk();
        $assignment = $this->asUser($this->colleague)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->colleagueCourse->id, 'title' => 'งานวิทย์',
        ])->assertCreated()->json('data.id');

        $url = "/api/v1/classrooms/{$this->classroom->id}/teachers/{$row['id']}";
        $this->asUser($this->owner)->deleteJson($url)->assertStatus(409)->assertJsonPath('code', 'course_in_use');
        $this->asUser($this->colleague)->deleteJson($url)->assertForbidden()->assertJsonPath('code', 'not_homeroom_teacher');

        $this->asUser($this->colleague)->deleteJson("/api/v1/assignments/{$assignment}")->assertNoContent();
        $this->asUser($this->owner)->deleteJson($url)->assertNoContent();
        $this->assertDatabaseMissing('classroom_teachers', ['id' => $row['id']]);
        $this->assertDatabaseMissing('course_classroom', ['classroom_id' => $this->classroom->id, 'course_id' => $this->colleagueCourse->id]);
        $this->asUser($this->colleague)->getJson("/api/v1/classrooms/{$this->classroom->id}")->assertNotFound();
        $this->asUser($this->colleague)->getJson('/api/v1/classrooms')->assertOk()->assertJsonCount(0, 'data');

        // A pending invitation is simply withdrawn.
        $pending = $this->invite('another@example.com');
        $this->asUser($this->owner)->deleteJson("/api/v1/classrooms/{$this->classroom->id}/teachers/{$pending['id']}")->assertNoContent();
        $this->assertSame(0, ClassroomTeacher::query()->count());
    }

    public function test_a_colleague_cannot_bind_a_course_to_a_classroom_they_were_not_invited_to(): void
    {
        $this->asUser($this->colleague)->putJson("/api/v1/courses/{$this->colleagueCourse->id}/classrooms", ['classroom_ids' => [$this->classroom->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['classroom_ids.0']);
        $this->asUser($this->colleague)->postJson('/api/v1/courses', [
            'code' => 'ว15102', 'name' => 'วิทย์ 5 เพิ่มเติม', 'subject_id' => $this->colleagueCourse->subject_id, 'grade_level' => 5,
            'semester' => 1, 'academic_year' => 2569, 'classroom_ids' => [$this->classroom->id],
        ])->assertStatus(422);
    }
}
