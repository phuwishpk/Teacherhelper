<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Schools\Pages\EditSchool;
use App\Filament\Resources\Students\Pages\ManageStudents;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §24.9.2, §24.13: the school's Google sign-in settings (allowed
 * domains, the students' switch, the automatic teacher approval of #71), removing every student's link at once,
 * and unlinking one student, all in Filament.
 */
class GoogleSignInAdminTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Classroom $classroom;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $teacher = $this->makeTeacher();
        $this->school = $teacher->school;
        $this->classroom = $this->makeClassroom($teacher);
        $this->admin = $this->makeAdmin();
        $this->actingAs($this->admin);
    }

    public function test_admin_removes_every_student_link_of_the_school(): void
    {
        $a = $this->enrollStudent($this->classroom, 1)['student'];
        $b = $this->enrollStudent($this->classroom, 2)['student'];
        $elsewhere = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1)['student'];
        $teacher = $this->classroom->teacher;
        foreach ([$a, $b, $elsewhere, $teacher] as $i => $user) {
            UserGoogleIdentity::create(['user_id' => $user->id, 'google_sub' => "sub-{$i}", 'email' => "u{$i}@school.ac.th", 'linked_via' => 'self', 'linked_at' => now()]);
        }

        Livewire::test(EditSchool::class, ['record' => $this->school->getRouteKey()])
            ->callAction('unlinkStudentGoogle')
            ->assertNotified('ลบการเชื่อม Google ของนักเรียนแล้ว 2 คน');

        // Other schools' students and the school's teachers keep theirs.
        $this->assertEqualsCanonicalizing([$elsewhere->id, $teacher->id], UserGoogleIdentity::query()->pluck('user_id')->all());
    }

    public function test_admin_unlinks_one_student(): void
    {
        $student = $this->enrollStudent($this->classroom, 1)['student'];
        $plain = $this->enrollStudent($this->classroom, 2)['student'];
        UserGoogleIdentity::create(['user_id' => $student->id, 'google_sub' => 'sub-1', 'email' => 'nong@school.ac.th', 'linked_via' => 'self', 'linked_at' => now()]);

        Livewire::test(ManageStudents::class)
            ->assertActionVisible(TestAction::make('unlinkGoogle')->table($student))
            ->assertActionHidden(TestAction::make('unlinkGoogle')->table($plain))
            ->callAction(TestAction::make('unlinkGoogle')->table($student))
            ->assertNotified('ยกเลิกการเชื่อม Google แล้ว');

        $this->assertSame(0, UserGoogleIdentity::count());
    }
}
