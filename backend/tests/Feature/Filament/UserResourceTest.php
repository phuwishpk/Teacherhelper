<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §7.5: admins approve and disable teacher accounts in Filament.
 */
class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->admin = $this->makeAdmin();
        $this->actingAs($this->admin);
    }

    public function test_the_users_page_lists_teachers(): void
    {
        $teacher = User::factory()->teacher()->pending()->create(['name' => 'ครูรออนุมัติ']);

        $this->get('/admin/users')->assertOk();

        Livewire::test(ManageUsers::class)
            ->assertCanSeeTableRecords([$teacher, $this->admin])
            ->assertSee('ครูรออนุมัติ');
    }

    public function test_the_menu_badge_counts_teachers_waiting_for_approval(): void
    {
        $this->assertNull(UserResource::getNavigationBadge());

        $school = $this->makeSchool();
        User::factory()->teacher($school)->pending()->count(2)->create();
        User::factory()->teacher()->pending()->create(); // another school
        User::factory()->teacher($school)->create(); // active

        $this->assertSame('3', UserResource::getNavigationBadge());
        $this->assertSame('warning', UserResource::getNavigationBadgeColor());

        // An admin of a school counts that school only.
        $this->actingAs(User::factory()->admin()->create(['school_id' => $school->id]));
        $this->assertSame('2', UserResource::getNavigationBadge());
    }

    public function test_admin_approves_a_pending_teacher(): void
    {
        $teacher = User::factory()->teacher()->pending()->create();

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('approve')->table($teacher))
            ->assertHasNoActionErrors()
            ->assertNotified();

        $teacher->refresh();
        $this->assertSame('active', $teacher->status);
        $this->assertSame($this->admin->id, $teacher->approved_by);
    }

    public function test_admin_disables_an_active_teacher_and_revokes_their_tokens(): void
    {
        $teacher = User::factory()->teacher()->create();
        $teacher->createToken('phone', ['teacher']);

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('disable')->table($teacher))
            ->assertHasNoActionErrors();

        $this->assertSame('disabled', $teacher->fresh()->status);
        $this->assertSame(0, $teacher->tokens()->count());

        // ...and can be approved again.
        Livewire::test(ManageUsers::class)->callAction(TestAction::make('approve')->table($teacher));
        $this->assertSame('active', $teacher->fresh()->status);
    }

    public function test_approve_and_disable_are_offered_only_where_they_apply(): void
    {
        $pending = User::factory()->teacher()->pending()->create();
        $active = User::factory()->teacher()->create();
        $student = User::factory()->student()->create();

        Livewire::test(ManageUsers::class)
            ->assertActionVisible(TestAction::make('approve')->table($pending))
            ->assertActionHidden(TestAction::make('disable')->table($pending))
            ->assertActionVisible(TestAction::make('disable')->table($active))
            ->assertActionHidden(TestAction::make('approve')->table($active))
            ->assertActionHidden(TestAction::make('approve')->table($student))
            ->assertActionHidden(TestAction::make('disable')->table($student))
            ->assertActionHidden(TestAction::make('disable')->table($this->admin));
    }

    // ---- an admin of a school (DESIGN §24.2, #70) ----

    /** @return array{0: User, 1: User, 2: User} [school admin, own teacher, other school's teacher], all pending teachers */
    private function twoSchools(): array
    {
        $school = $this->makeSchool(['name' => 'โรงเรียนเรา']);
        $other = $this->makeSchool(['name' => 'โรงเรียนอื่น']);
        $schoolAdmin = User::factory()->admin()->create(['school_id' => $school->id, 'name' => 'แอดมินโรงเรียน']);
        $own = User::factory()->teacher($school)->pending()->create(['name' => 'ครูโรงเรียนเรา']);
        $foreign = User::factory()->teacher($other)->pending()->create(['name' => 'ครูโรงเรียนอื่น']);

        return [$schoolAdmin, $own, $foreign];
    }

    public function test_an_admin_of_a_school_lists_only_that_school(): void
    {
        [$schoolAdmin, $own, $foreign] = $this->twoSchools();

        $this->actingAs($schoolAdmin);
        $this->get('/admin/users')->assertOk()->assertDontSee('ครูโรงเรียนอื่น');
        Livewire::test(ManageUsers::class)
            ->assertCanSeeTableRecords([$own, $schoolAdmin])
            ->assertCanNotSeeTableRecords([$foreign, $this->admin]) // nor the system admin
            ->assertTableFilterHidden('school_id');

        // A system admin still sees every school.
        $this->actingAs($this->admin);
        Livewire::test(ManageUsers::class)
            ->assertCanSeeTableRecords([$own, $foreign, $schoolAdmin, $this->admin])
            ->assertTableFilterVisible('school_id');
    }

    public function test_an_admin_of_a_school_cannot_open_approve_disable_or_edit_another_schools_teacher(): void
    {
        [$schoolAdmin, $own, $foreign] = $this->twoSchools();
        $foreignActive = User::factory()->teacher($foreign->school)->create(['name' => 'ครูอื่นที่ใช้งานอยู่']);

        // The policy behind every action and the edit modal (there is no record page).
        foreach (['view', 'update', 'approve'] as $ability) {
            $this->assertFalse($schoolAdmin->can($ability, $foreign), $ability);
            $this->assertTrue($schoolAdmin->can($ability, $own), $ability);
            $this->assertTrue($this->admin->can($ability, $foreign), $ability);
        }
        $this->assertFalse($schoolAdmin->can('disable', $foreignActive));
        $this->assertTrue($this->admin->can('disable', $foreignActive));
        $this->assertFalse($schoolAdmin->can('update', $this->admin), 'a system admin is out of reach');

        // Through the panel: the rows are not there, so the actions never run.
        $this->actingAs($schoolAdmin);
        foreach ([[$foreign, 'approve', []], [$foreignActive, 'disable', []], [$foreign, 'edit', ['name' => 'แก้ข้ามโรงเรียน']]] as [$target, $action, $data]) {
            $this->assertRecordRefused(fn () => Livewire::test(ManageUsers::class)->callAction(TestAction::make($action)->table($target), $data));
        }
        $this->assertSame(['pending', 'ครูโรงเรียนอื่น', null], [$foreign->fresh()->status, $foreign->fresh()->name, $foreign->fresh()->approved_by]);
        $this->assertSame('active', $foreignActive->fresh()->status);

        // Its own teacher works as before.
        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('approve')->table($own))
            ->assertHasNoActionErrors();
        $this->assertSame(['active', $schoolAdmin->id], [$own->fresh()->status, $own->fresh()->approved_by]);

        // The system admin still approves the other school's teacher.
        $this->actingAs($this->admin);
        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('approve')->table($foreign))
            ->assertHasNoActionErrors();
        $this->assertSame('active', $foreign->fresh()->status);
    }

    public function test_an_admin_of_a_school_keeps_created_and_edited_accounts_in_its_school(): void
    {
        [$schoolAdmin, $own, $foreign] = $this->twoSchools();
        $this->actingAs($schoolAdmin);

        // Neither another school nor "system level" (an admin without a school).
        Livewire::test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'แอดมินใหม่', 'role' => User::ROLE_ADMIN, 'email' => 'new-admin@example.com',
                'school_id' => $foreign->school_id, 'status' => User::STATUS_ACTIVE, 'password' => 'secret1234',
            ])
            ->assertHasActionErrors(['school_id']);
        $this->assertNull(User::query()->where('email', 'new-admin@example.com')->first(), 'another school is not an option');
        Livewire::test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'แอดมินใหม่', 'role' => User::ROLE_ADMIN, 'email' => 'new-admin@example.com',
                'school_id' => null, 'status' => User::STATUS_ACTIVE, 'password' => 'secret1234',
            ])
            ->assertHasActionErrors(['school_id' => 'required']);
        $this->assertNull(User::query()->where('email', 'new-admin@example.com')->first(), 'no system admin from a school admin');

        Livewire::test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'ครูใหม่', 'role' => User::ROLE_TEACHER, 'email' => 'new-teacher@example.com',
                'status' => User::STATUS_ACTIVE, 'password' => 'secret1234',
            ])
            ->assertHasNoActionErrors();
        $this->assertSame($schoolAdmin->school_id, User::query()->where('email', 'new-teacher@example.com')->value('school_id'));

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($own), ['school_id' => $foreign->school_id]);
        $this->assertSame($schoolAdmin->school_id, $own->fresh()->school_id);
    }

    /** Filament resolves table records through the scoped query: a row outside it is refused. */
    private function assertRecordRefused(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->assertStringContainsString('no longer exists', $e->getMessage());

            return;
        }
        $this->fail('the action ran on a record outside the admin\'s school');
    }

    public function test_teachers_cannot_open_the_panel(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->get('/admin/users')->assertForbidden();
    }
}
