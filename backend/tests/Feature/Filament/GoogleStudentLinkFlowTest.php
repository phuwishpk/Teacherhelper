<?php

namespace Tests\Feature\Filament;

use App\Domain\Auth\Google\StudentRosterLink;
use App\Filament\Pages\GoogleStudentLinkFlow;
use App\Models\Classroom;
use App\Models\GoogleEvent;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §32: the admin page that draws how a student's Google account
 * links automatically and dry-runs both paths for one e-mail, writing
 * nothing.
 */
class GoogleStudentLinkFlowTest extends TestCase
{
    use RefreshDatabase;

    private Classroom $classroom;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->classroom = $this->makeClassroom($this->makeTeacher(), ['name' => 'ป.5/1']);
        $this->student = $this->enrollStudent($this->classroom, 3, 'ด.ญ. นงนุช')['student'];
    }

    public function test_only_an_admin_opens_the_page(): void
    {
        $this->actingAs($this->makeAdmin())->get('/admin/google-student-link')->assertOk()->assertSee('ทาง ก');
        $this->actingAs($this->makeTeacher())->get('/admin/google-student-link')->assertForbidden();
    }

    public function test_a_synced_roster_row_with_the_same_email_links_automatically(): void
    {
        $this->roster($this->student, 'sub-1', 'Nong@School.ac.th');
        $this->actingAs($this->makeAdmin());

        Livewire::test(GoogleStudentLinkFlow::class)
            ->set('email', 'nong@school.ac.th')
            ->call('check')
            ->assertHasNoErrors()
            ->assertSee('sub-1')
            ->assertSee(GoogleStudentLinkFlow::ROSTER_LABELS[StudentRosterLink::OK])
            ->assertSee('ใช้บัญชีเดิม');

        $this->assertSame(0, UserGoogleIdentity::count());
        $this->assertSame(0, GoogleEvent::count());
    }

    public function test_it_explains_why_a_student_needs_the_pin(): void
    {
        $this->roster($this->student, 'sub-1', 'old@school.ac.th');
        $this->actingAs($this->makeAdmin());

        Livewire::test(GoogleStudentLinkFlow::class)
            ->set('email', 'nong@school.ac.th')
            ->set('sub', 'sub-1')
            ->call('check')
            ->assertSee(GoogleStudentLinkFlow::ROSTER_LABELS[StudentRosterLink::EMAIL_NOT_ON_SUB_ROW]);

        Livewire::test(GoogleStudentLinkFlow::class)
            ->set('email', 'nobody@school.ac.th')
            ->call('check')
            ->assertSee(GoogleStudentLinkFlow::ROSTER_LABELS[StudentRosterLink::NO_SUB_ROW]);
    }

    public function test_another_workspace_does_not_find_the_student(): void
    {
        $this->roster($this->student, 'sub-1', 'nong@school.ac.th');
        $other = $this->makeTeacher();
        $this->actingAs($this->makeAdmin());

        Livewire::test(GoogleStudentLinkFlow::class)
            ->set('email', 'nong@school.ac.th')
            ->set('schoolId', $other->school_id)
            ->call('check')
            ->assertSee('ห้องประจำชั้นจะสร้างบัญชีใหม่');
    }

    public function test_the_check_matches_each_reason(): void
    {
        $check = app(StudentRosterLink::class);
        $this->assertSame(StudentRosterLink::NO_SUB_ROW, $check->check('sub-1', 'nong@school.ac.th')['result']);

        $this->roster($this->student, 'sub-1', 'nong@school.ac.th');
        $this->assertSame(StudentRosterLink::OK, $check->check('sub-1', 'nong@school.ac.th')['result']);

        $twin = $this->enrollStudent($this->classroom, 4)['student'];
        DB::table('classroom_students')->where('student_id', $twin->id)->update(['google_email' => 'nong@school.ac.th']);
        $this->assertSame(StudentRosterLink::SEVERAL_STUDENTS, $check->check('sub-1', 'nong@school.ac.th')['result']);
        DB::table('classroom_students')->where('student_id', $twin->id)->update(['google_email' => null]);

        $this->student->update(['status' => User::STATUS_DISABLED]);
        $this->assertSame(StudentRosterLink::STUDENT_NOT_ACTIVE, $check->check('sub-1', 'nong@school.ac.th')['result']);
        $this->student->update(['status' => User::STATUS_ACTIVE]);

        UserGoogleIdentity::create(['user_id' => $this->student->id, 'google_sub' => 'sub-other', 'email' => 'x@school.ac.th', 'linked_via' => UserGoogleIdentity::VIA_PIN_CONFIRM, 'linked_at' => now()]);
        $this->assertSame(StudentRosterLink::OTHER_IDENTITY, $check->check('sub-1', 'nong@school.ac.th')['result']);
    }

    private function roster(User $student, string $sub, string $email): void
    {
        DB::table('classroom_students')->where('student_id', $student->id)->update(['google_user_id' => $sub, 'google_email' => $email]);
    }
}
