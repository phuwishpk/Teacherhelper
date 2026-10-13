<?php

namespace Tests\Feature\Filament;

use App\Domain\Classrooms\CourseRequests;
use App\Exceptions\ApiException;
use App\Filament\Resources\Classrooms\ClassroomResource;
use App\Filament\Resources\Classrooms\Pages\ManageClassrooms;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\ClassroomCourseRequest;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §24.6, §24.7, §24.8: an admin of the school closes, reopens and
 * deletes classrooms in Filament with the same rules as the app, and binds
 * subject teachers' courses directly.
 */
class ClassroomResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1']);
    }

    public function test_every_admin_sees_every_classroom(): void
    {
        $other = $this->makeClassroom($this->makeTeacher(), ['name' => 'ห้องของครูอีกคน']);
        $this->actingAs($this->makeAdmin());

        $this->get('/admin/classrooms')->assertOk();
        Livewire::test(ManageClassrooms::class)
            ->assertCanSeeTableRecords([$this->classroom, $other])
            ->assertTableActionHidden('reopen', $this->classroom);
    }

    public function test_a_system_admin_sees_every_school_and_filters_closed_ones(): void
    {
        $closed = $this->makeClassroom($this->makeTeacher(), ['closed_at' => now()]);
        $this->actingAs($this->makeAdmin());

        Livewire::test(ManageClassrooms::class)
            ->assertCanSeeTableRecords([$this->classroom, $closed])
            ->filterTable('state', 'closed')
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$this->classroom]);
    }

    public function test_admin_closes_and_reopens_a_classroom(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test(ManageClassrooms::class)
            ->callAction(TestAction::make('close')->table($this->classroom))
            ->assertHasNoActionErrors()
            ->assertNotified();
        $this->classroom->refresh();
        $this->assertTrue($this->classroom->isClosed());
        $this->assertSame($admin->id, $this->classroom->closed_by);

        Livewire::test(ManageClassrooms::class)
            ->assertTableActionHidden('close', $this->classroom)
            ->callAction(TestAction::make('reopen')->table($this->classroom))
            ->assertNotified();
        $this->assertFalse($this->classroom->refresh()->isClosed());
    }

    public function test_admin_deletes_an_empty_classroom_but_not_one_with_work(): void
    {
        $this->actingAs($this->makeAdmin());
        $student = $this->enrollStudent($this->classroom)['student'];
        $assignment = Assignment::factory()->create(['classroom_id' => $this->classroom->id]);
        Submission::create(['assignment_id' => $assignment->id, 'student_id' => $student->id, 'status' => Submission::STATUS_AWAITING_SCAN]);

        Livewire::test(ManageClassrooms::class)
            ->callAction(TestAction::make('delete')->table($this->classroom))
            ->assertNotified('ลบห้องไม่ได้');
        $this->assertDatabaseHas('classrooms', ['id' => $this->classroom->id]);

        $empty = $this->makeClassroom($this->teacher);
        $this->enrollStudent($empty, 1, 'นักเรียนห้องว่าง');
        Livewire::test(ManageClassrooms::class)
            ->callAction(TestAction::make('delete')->table($empty))
            ->assertNotified();
        $this->assertDatabaseMissing('classrooms', ['id' => $empty->id]);
        $this->assertSame(2, User::query()->where('role', User::ROLE_STUDENT)->count());
    }

    public function test_admin_binds_a_subject_teachers_course_directly(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $subjectTeacher = $this->makeTeacher($this->teacher->school, ['name' => 'ครูวิทย์']);
        $course = $this->makeCourse($subjectTeacher, [], ['code' => 'ว15101', 'name' => 'วิทยาศาสตร์ 5']);
        $otherSchool = $this->makeCourse($this->makeTeacher(), [], ['code' => 'ว15199']);
        $pending = ClassroomCourseRequest::create(['classroom_id' => $this->classroom->id, 'course_id' => $course->id, 'requested_by' => $subjectTeacher->id]);

        $options = ClassroomResource::courseOptions($this->classroom);
        $this->assertArrayHasKey($course->id, $options);
        $this->assertArrayNotHasKey($otherSchool->id, $options);
        $this->assertStringContainsString('ครูวิทย์', $options[$course->id]);

        Livewire::test(ManageClassrooms::class)
            ->callAction(TestAction::make('assignCourse')->table($this->classroom), ['course_id' => $course->id])
            ->assertHasNoActionErrors()
            ->assertNotified('ผูกรายวิชา ว15101 กับห้อง ป.5/1 แล้ว');

        $this->assertDatabaseHas('course_classroom', ['course_id' => $course->id, 'classroom_id' => $this->classroom->id]);
        $this->assertSame(ClassroomCourseRequest::STATUS_CANCELLED, $pending->refresh()->status);
        $row = ClassroomCourseRequest::query()->where('origin', ClassroomCourseRequest::ORIGIN_ADMIN)->sole();
        $this->assertSame([ClassroomCourseRequest::STATUS_APPROVED, $admin->id, $admin->id], [$row->status, $row->requested_by, $row->decided_by]);
        $this->assertSame(['ว15101 วิทยาศาสตร์ 5 (ครูวิทย์)'], ClassroomResource::subjectTeachers($this->classroom->refresh()->load('courses.creator')));
        $this->assertArrayNotHasKey($course->id, ClassroomResource::courseOptions($this->classroom));
        $html = (string) ClassroomResource::requestsHtml($this->classroom);
        $this->assertStringContainsString('admin กำหนด', $html);
        $this->assertStringContainsString('ยกเลิก', $html);

        // Bound already (e.g. approved meanwhile): the admin is told, nothing changes.
        try {
            app(CourseRequests::class)->assignByAdmin($admin, $this->classroom, $course);
            $this->fail('a bound course is refused');
        } catch (ApiException $e) {
            $this->assertSame('course_already_in_classroom', $e->errorCode);
        }
        $this->assertSame(1, ClassroomCourseRequest::query()->where('origin', ClassroomCourseRequest::ORIGIN_ADMIN)->count());

        // A closed classroom takes no new subject teacher.
        $this->classroom->forceFill(['closed_at' => now()])->save();
        Livewire::test(ManageClassrooms::class)->assertTableActionHidden('assignCourse', $this->classroom);
    }
}
