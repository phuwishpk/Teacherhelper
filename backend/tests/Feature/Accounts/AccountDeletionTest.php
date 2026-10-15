<?php

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\AccountDeletion;
use App\Domain\Students\CredentialIssuer;
use App\Filament\Resources\Students\Pages\ManageStudents;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §31: an admin deletes a teacher or student account. It is blocked
 * at once, a teacher's own classrooms are closed with it, and everything
 * comes back with "กู้คืนบัญชี" within the 30 days.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private Classroom $room;

    private Classroom $oldRoom;

    private Classroom $colleagueRoom;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->admin = $this->makeAdmin();
        $this->teacher = $this->makeTeacher(null, ['email' => 'kru@example.test']);
        $this->room = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1']);
        $this->oldRoom = $this->makeClassroom($this->teacher, ['name' => 'ป.4/1', 'closed_at' => now()->subYear()]);
        $this->colleagueRoom = $this->makeClassroom($this->makeTeacher(), ['name' => 'ป.6/1']);
        $this->student = $this->enrollStudent($this->room, 1, 'เด็กชายทดสอบ')['student'];
        $this->enrollStudent($this->colleagueRoom, 1, 'อีกห้อง');
        $this->actingAs($this->admin);
    }

    public function test_deleting_a_teacher_blocks_the_account_and_closes_their_classrooms(): void
    {
        $this->teacher->createToken('phone', ['teacher']);
        $this->assertSame(['classrooms' => 2, 'students' => 1, 'submissions' => 0], AccountDeletion::teacherSummary($this->teacher));

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('deleteAccount')->table($this->teacher), ['confirm' => false])
            ->assertHasActionErrors(['confirm']);
        $this->assertFalse($this->teacher->refresh()->isDeleted());

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('deleteAccount')->table($this->teacher), ['confirm' => true])
            ->assertHasNoActionErrors();

        $this->teacher->refresh();
        $this->assertTrue($this->teacher->isDeleted());
        $this->assertFalse($this->teacher->isActive());
        $this->assertSame($this->admin->id, $this->teacher->deleted_by);
        $this->assertSame(0, $this->teacher->tokens()->count());
        $this->assertEquals($this->teacher->deleted_at->copy()->addDays(30), AccountDeletion::purgeAt($this->teacher));

        $this->room->refresh();
        $this->assertNotNull($this->room->deleted_at);
        $this->assertEquals($this->room->deleted_at, $this->room->closed_at);
        $this->assertNotNull($this->oldRoom->refresh()->deleted_at);
        $this->assertTrue($this->oldRoom->closed_at->lt($this->oldRoom->deleted_at), 'a room closed before keeps its date');
        $this->assertNull($this->colleagueRoom->refresh()->deleted_at);
        $this->assertNull($this->colleagueRoom->closed_at);
        $this->assertFalse($this->student->refresh()->isDeleted(), 'the students stay');

        $this->asGuest()->postJson('/api/v1/auth/teacher/login', ['email' => 'kru@example.test', 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'account_not_active');
        $this->assertFalse($this->admin->can('reopen', $this->room));
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->admin->id, 'action' => AuditLog::ACCOUNT_DELETE, 'target_user_id' => $this->teacher->id]);

        $this->actingAs($this->admin);
        Livewire::test(ManageUsers::class)
            ->assertSee('กู้คืนได้ถึง')
            ->assertActionHidden(TestAction::make('deleteAccount')->table($this->teacher))
            ->assertActionHidden(TestAction::make('approve')->table($this->teacher))
            ->assertActionHidden(TestAction::make('impersonate')->table($this->teacher))
            ->assertActionVisible(TestAction::make('restoreAccount')->table($this->teacher));
    }

    public function test_restoring_a_teacher_reopens_only_the_classrooms_the_deletion_closed(): void
    {
        app(AccountDeletion::class)->delete($this->admin, $this->teacher);

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('restoreAccount')->table($this->teacher))
            ->assertHasNoActionErrors();

        $this->teacher->refresh();
        $this->assertFalse($this->teacher->isDeleted());
        $this->assertTrue($this->teacher->isActive());
        $this->assertNull($this->teacher->deleted_by);
        $this->assertNull($this->room->refresh()->deleted_at);
        $this->assertNull($this->room->closed_at);
        $this->assertNull($this->oldRoom->refresh()->deleted_at);
        $this->assertNotNull($this->oldRoom->closed_at, 'closed before the deletion, closed after the restore');
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLog::ACCOUNT_RESTORE, 'target_user_id' => $this->teacher->id]);

        $this->asGuest()->postJson('/api/v1/auth/teacher/login', ['email' => 'kru@example.test', 'password' => 'password'])->assertOk();
    }

    public function test_deleting_a_student_blocks_every_login_and_restore_lets_them_back(): void
    {
        $this->student->forceFill(['username' => 'kid01'])->save();
        app(CredentialIssuer::class)->setPassword($this->student, 'ปลาทอง99');
        $this->student->createToken('phone', ['student']);

        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('deleteAccount')->table($this->student), ['confirm' => true])
            ->assertHasNoActionErrors();

        $this->assertTrue($this->student->refresh()->isDeleted());
        $this->assertSame(0, $this->student->tokens()->count());
        $this->assertNull($this->room->refresh()->deleted_at, 'deleting a student leaves the classroom alone');
        $this->asGuest()->postJson('/api/v1/auth/student/login', ['username' => 'kid01', 'password' => 'ปลาทอง99'])
            ->assertForbidden()->assertJsonPath('code', 'account_not_active');

        $this->actingAs($this->admin);
        Livewire::test(ManageStudents::class)
            ->assertActionHidden(TestAction::make('editStudent')->table($this->student))
            ->assertActionHidden(TestAction::make('enable')->table($this->student))
            ->assertActionHidden(TestAction::make('resetPassword')->table($this->student))
            ->callAction(TestAction::make('restoreAccount')->table($this->student))
            ->assertHasNoActionErrors();

        $this->asGuest()->postJson('/api/v1/auth/student/login', ['username' => 'kid01', 'password' => 'ปลาทอง99'])->assertOk();
    }

    public function test_who_can_be_deleted_and_by_whom(): void
    {
        $other = $this->makeAdmin();
        $merged = $this->enrollStudent($this->colleagueRoom, 2, 'ซ้ำ')['student'];
        $merged->forceFill(['status' => User::STATUS_DISABLED, 'merged_into_id' => $this->student->id])->save();

        $this->assertTrue($this->admin->can('deleteAccount', $this->teacher));
        $this->assertTrue($this->admin->can('deleteAccount', $this->student));
        $this->assertFalse($this->admin->can('deleteAccount', $other), 'never an admin');
        $this->assertFalse($this->admin->can('deleteAccount', $this->admin), 'never yourself');
        $this->assertFalse($this->admin->can('deleteAccount', $merged), 'a merged account is the evidence of the merge');
        $this->assertFalse($this->teacher->can('deleteAccount', $this->student), 'admins only');
        $this->assertFalse($this->admin->can('restoreAccount', $this->student), 'nothing to restore');
    }
}
