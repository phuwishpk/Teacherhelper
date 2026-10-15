<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Students\Pages\DuplicateStudents;
use App\Filament\Resources\Students\Pages\ManageStudents;
use App\Filament\Resources\Students\StudentMergeSupport;
use App\Models\Classroom;
use App\Models\StudentMerge;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §24.5, §24.13: an admin of the school finds students, compares two
 * accounts and merges them in Filament with the same StudentMerger as the app,
 * and reviews the likely duplicates.
 */
class StudentResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $room1;

    private Classroom $room2;

    private User $keep;

    private User $merge;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->teacher = $this->makeTeacher();
        $this->room1 = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1']);
        $this->room2 = $this->makeClassroom($this->makeTeacher($this->teacher->school), ['name' => 'ป.6/1']);
        $this->keep = $this->enrollStudent($this->room1, 1, 'สมชาย ใจดี')['student'];
        $this->merge = $this->enrollStudent($this->room2, 7, 'ด.ช.สมชาย ใจดี')['student'];
        $this->admin = $this->makeAdmin();
        $this->actingAs($this->admin);
    }

    public function test_the_list_shows_every_student(): void
    {
        $elsewhere = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1, 'โรงเรียนอื่น')['student'];

        $this->get('/admin/students')->assertOk();
        Livewire::test(ManageStudents::class)
            ->assertCanSeeTableRecords([$this->keep, $this->merge, $elsewhere])
            ->assertCanNotSeeTableRecords([$this->teacher, $this->admin])
            ->searchTable('ใจดี')
            ->assertCanSeeTableRecords([$this->keep, $this->merge]);
    }

    public function test_admin_merges_two_accounts_after_the_comparison(): void
    {
        $this->assertStringContainsString('เลือกบัญชีที่จะรวม', $this->previewHtml($this->keep, null));
        $html = $this->previewHtml($this->keep, $this->merge);
        $this->assertStringContainsString('ถูกรวม: ด.ช.สมชาย ใจดี', $html);
        $this->assertStringContainsString('ป.6/1 (', $html);
        $this->assertStringContainsString('ย้อนกลับไม่ได้', $html);

        Livewire::test(ManageStudents::class)
            ->mountAction(TestAction::make('merge')->table($this->keep))
            ->fillForm(['merge_id' => $this->merge->id])
            ->callMountedAction()
            ->assertHasActionErrors(['confirm']);
        $this->assertNull($this->merge->refresh()->merged_into_id);

        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('merge')->table($this->keep), ['merge_id' => $this->merge->id, 'confirm' => true])
            ->assertHasNoActionErrors()
            ->assertNotified('รวมบัญชี ด.ช.สมชาย ใจดี เข้า สมชาย ใจดี แล้ว');

        $this->merge->refresh();
        $this->assertSame($this->keep->id, $this->merge->merged_into_id);
        $this->assertSame('disabled', $this->merge->status);
        $this->assertDatabaseHas('classroom_students', ['classroom_id' => $this->room2->id, 'student_id' => $this->keep->id, 'student_number' => 7]);
        $this->assertSame($this->admin->id, StudentMerge::query()->sole()->merged_by);

        Livewire::test(ManageStudents::class)
            ->assertSee('ถูกรวมเข้า สมชาย ใจดี')
            ->assertTableActionHidden('merge', $this->merge);
    }

    public function test_a_conflict_keeps_the_modal_open_and_writes_nothing(): void
    {
        $this->keep->forceFill(['student_code' => '6601'])->save();
        $this->merge->forceFill(['student_code' => '6602'])->save();

        $html = $this->previewHtml($this->keep, $this->merge);
        $this->assertStringContainsString('รวมไม่ได้', $html);
        $this->assertStringContainsString('เลขประจำตัวต่างกัน', $html);

        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('merge')->table($this->keep), ['merge_id' => $this->merge->id, 'confirm' => true])
            ->assertNotified('รวมบัญชีไม่ได้ เพราะข้อมูลของสองบัญชีชนกัน');

        $this->assertNull($this->merge->refresh()->merged_into_id);
        $this->assertSame(0, StudentMerge::query()->count());
    }

    public function test_every_admin_may_merge(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test(ManageStudents::class)->assertCanSeeTableRecords([$this->keep]);
        $this->assertTrue(auth()->user()->can('mergeStudents', [$this->keep, $this->merge]));
    }

    public function test_the_duplicates_page_lists_pairs_and_merges_either_way(): void
    {
        DB::table('classroom_students')->where('student_id', $this->keep->id)->update(['google_email' => 'som@school.ac.th']);
        DB::table('classroom_students')->where('student_id', $this->merge->id)->update(['google_email' => 'SOM@school.ac.th']);

        $this->get('/admin/students/duplicates')->assertOk()->assertSee('อีเมล Google เดียวกัน');

        Livewire::test(DuplicateStudents::class)
            ->assertSee('ด.ช.สมชาย ใจดี')
            ->assertSee('ชื่อเดียวกัน')
            ->callAction(TestAction::make('merge')->arguments(['keep' => $this->merge->id, 'merge' => $this->keep->id]), ['confirm' => true])
            ->assertHasNoActionErrors()
            ->assertNotified('รวมบัญชี สมชาย ใจดี เข้า ด.ช.สมชาย ใจดี แล้ว');

        $this->assertSame($this->merge->id, $this->keep->refresh()->merged_into_id);
        Livewire::test(DuplicateStudents::class)->assertSee('ไม่พบบัญชีที่อาจซ้ำ');
    }

    public function test_a_system_admin_picks_the_school_on_the_duplicates_page(): void
    {
        $this->actingAs($this->makeAdmin());
        $otherSchool = $this->makeSchool(['name' => 'ฮฮฮ โรงเรียนท้ายรายการ']);

        Livewire::test(DuplicateStudents::class)
            ->set('schoolId', $this->teacher->school_id)
            ->assertSee('ด.ช.สมชาย ใจดี')
            ->set('schoolId', $otherSchool->id)
            ->assertSee('ไม่พบบัญชีที่อาจซ้ำ');
    }

    public function test_a_pair_from_two_workspaces_can_be_compared(): void
    {
        // Pupils sit in many teachers' classrooms (DESIGN §29.13.3): one workspace is no longer required.
        $elsewhere = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1, 'สมชาย ใจดี')['student'];

        $data = StudentMergeSupport::previewData($this->keep, $elsewhere);
        $this->assertNull($data['error']);
        $this->assertTrue($data['preview']['can_merge']);

        $this->assertNull($elsewhere->refresh()->merged_into_id);
        $this->assertSame(0, StudentMerge::query()->count());
    }

    private function previewHtml(User $keep, ?User $merge): string
    {
        return view('filament.student-merge-preview', StudentMergeSupport::previewData($keep, $merge))->render();
    }

    public function test_admin_edits_name_student_code_and_username(): void
    {
        $this->keep->forceFill(['username' => 'somchai1'])->save();
        $this->makeStudentWith(['username' => 'taken.name', 'student_code' => '9001', 'school_id' => $this->keep->school_id]);

        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('editStudent')->table($this->keep), ['name' => 'x', 'student_code' => '9001', 'username' => 'Taken.Name'])
            ->assertHasActionErrors(['student_code', 'username']);
        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('editStudent')->table($this->keep), ['name' => 'x', 'student_code' => 'ไม่ได้', 'username' => 'ab'])
            ->assertHasActionErrors(['student_code', 'username']);
        $this->assertSame('สมชาย ใจดี', $this->keep->refresh()->name);
        Livewire::test(ManageStudents::class)->callAction(TestAction::make('editStudent')->table($this->keep), ['name' => '  สมชาย ใจงาม ', 'student_code' => '6701', 'username' => ' SomChai.J '])
            ->assertHasNoActionErrors();

        $this->keep->refresh();
        $this->assertSame('สมชาย ใจงาม', $this->keep->name);
        $this->assertSame('6701', $this->keep->student_code);
        $this->assertSame('somchai.j', $this->keep->username);
    }

    public function test_admin_resets_the_password_and_removes_the_google_link_unless_kept(): void
    {
        $this->keep->credential()->update(['must_change_password' => false, 'failed_pin_attempts' => 4]);
        $this->keep->createToken('phone', ['student']);
        $this->linkGoogle($this->keep);

        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('resetPassword')->table($this->keep), ['keep_google' => false])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $credential = $this->keep->credential()->first();
        $this->assertTrue((bool) $credential->must_change_password);
        $this->assertSame(0, (int) $credential->failed_pin_attempts);
        $this->assertSame(0, $this->keep->tokens()->count());
        $this->assertDatabaseMissing('user_google_identities', ['user_id' => $this->keep->id]);

        $this->linkGoogle($this->keep);
        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('resetPassword')->table($this->keep), ['keep_google' => true])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('user_google_identities', ['user_id' => $this->keep->id]);
    }

    public function test_admin_disables_and_enables_a_student(): void
    {
        $this->keep->createToken('phone', ['student']);

        Livewire::test(ManageStudents::class)
            ->assertActionHidden(TestAction::make('enable')->table($this->keep))
            ->callAction(TestAction::make('disable')->table($this->keep))
            ->assertHasNoActionErrors();
        $this->assertSame(User::STATUS_DISABLED, $this->keep->refresh()->status);
        $this->assertSame(0, $this->keep->tokens()->count());

        Livewire::test(ManageStudents::class)
            ->assertActionHidden(TestAction::make('disable')->table($this->keep))
            ->callAction(TestAction::make('enable')->table($this->keep))
            ->assertHasNoActionErrors();
        $this->assertSame(User::STATUS_ACTIVE, $this->keep->refresh()->status);
    }

    public function test_admin_opens_the_app_as_the_student(): void
    {
        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('impersonate')->table($this->keep))
            ->assertHasNoActionErrors()
            ->assertRedirectContains('/app/#/impersonate?ticket=');
    }

    public function test_a_merged_account_keeps_its_state(): void
    {
        $this->merge->forceFill(['status' => User::STATUS_DISABLED, 'merged_into_id' => $this->keep->id])->save();

        $page = Livewire::test(ManageStudents::class);
        foreach (['editStudent', 'resetPassword', 'impersonate', 'disable', 'enable'] as $action) {
            $page->assertActionHidden(TestAction::make($action)->table($this->merge));
        }
    }

    public function test_teachers_cannot_use_the_admin_student_actions(): void
    {
        $this->assertFalse($this->teacher->can('adminManageStudent', $this->keep));
        $this->assertFalse($this->teacher->can('enableStudent', $this->keep));
        $this->assertFalse($this->teacher->can('disable', $this->keep));
        $this->assertTrue($this->admin->can('adminManageStudent', $this->keep));
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeStudentWith(array $attributes): User
    {
        $student = $this->enrollStudent($this->room2, 30, 'คนอื่น')['student'];
        $student->forceFill($attributes)->save();

        return $student;
    }

    private function linkGoogle(User $student): void
    {
        DB::table('user_google_identities')->insert([
            'user_id' => $student->id,
            'google_sub' => 'sub-'.$student->id.'-'.uniqid(),
            'email' => 'kid'.$student->id.'@example.test',
            'linked_via' => 'self',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
