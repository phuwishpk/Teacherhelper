<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Classrooms\Pages\ManageClassrooms;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §24.6, §24.8: an admin of the school closes, reopens and deletes
 * classrooms in Filament with the same rules as the app.
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

    public function test_a_school_admin_sees_only_the_classrooms_of_their_school(): void
    {
        $other = $this->makeClassroom($this->makeTeacher(), ['name' => 'ห้องโรงเรียนอื่น']);
        $this->actingAs($this->makeAdmin(['school_id' => $this->teacher->school_id]));

        $this->get('/admin/classrooms')->assertOk();
        Livewire::test(ManageClassrooms::class)
            ->assertCanSeeTableRecords([$this->classroom])
            ->assertCanNotSeeTableRecords([$other])
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
        $admin = $this->makeAdmin(['school_id' => $this->teacher->school_id]);
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
        $this->actingAs($this->makeAdmin(['school_id' => $this->teacher->school_id]));
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

    public function test_an_admin_of_another_school_cannot_act(): void
    {
        $this->actingAs($this->makeAdmin(['school_id' => $this->makeSchool()->id]));

        Livewire::test(ManageClassrooms::class)->assertCanNotSeeTableRecords([$this->classroom]);
        $this->assertFalse($this->makeAdmin(['school_id' => $this->makeSchool()->id])->can('close', $this->classroom));
        $this->assertTrue($this->makeAdmin()->can('delete', $this->classroom));
    }
}
