<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\School;
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

        $this->assertSame('3', UserResource::getNavigationBadge(), 'every workspace counts: one admin level (DESIGN §29.13.1)');
        $this->assertSame('warning', UserResource::getNavigationBadgeColor());
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
            ->assertActionVisible(TestAction::make('disable')->table($student))
            ->assertActionHidden(TestAction::make('disable')->table($this->admin));
    }

    // ---- an admin of a school (DESIGN §24.2, #70) ----

    public function test_every_admin_sees_and_approves_every_teacher(): void
    {
        // One admin level (DESIGN §29.13.1): there is no "admin of a school" any more.
        $other = User::factory()->admin()->create(['name' => 'แอดมินคนที่สอง']);
        $own = User::factory()->teacher($this->makeSchool(['name' => 'พื้นที่ ก']))->pending()->create(['name' => 'ครู ก']);
        $foreign = User::factory()->teacher($this->makeSchool(['name' => 'พื้นที่ ข']))->pending()->create(['name' => 'ครู ข']);

        $this->actingAs($other);
        $this->get('/admin/users')->assertOk()->assertSee('ครู ข');
        Livewire::test(ManageUsers::class)
            ->assertCanSeeTableRecords([$own, $foreign, $other, $this->admin])
            ->callAction(TestAction::make('approve')->table($foreign))
            ->assertHasNoActionErrors();
        $this->assertSame(['active', $other->id], [$foreign->fresh()->status, $foreign->fresh()->approved_by]);
        foreach (['view', 'update', 'approve'] as $ability) {
            $this->assertTrue($other->can($ability, $own), $ability);
        }
        $this->assertTrue($other->can('update', $this->admin));
    }

    // ---- "เพิ่มผู้ใช้" (the create action) ----

    public function test_admin_adds_a_teacher_who_gets_a_workspace_and_can_create_a_classroom(): void
    {
        $schools = School::query()->count();

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('create'), data: [
                'name' => 'ครูเพิ่มโดยผู้ดูแล',
                'role' => User::ROLE_TEACHER,
                'email' => 'added-teacher@example.com',
                'status' => User::STATUS_ACTIVE,
                'password' => 'secret1234',
            ])
            ->assertHasNoActionErrors();

        $teacher = User::query()->where('email', 'added-teacher@example.com')->sole();
        $this->assertSame(User::ROLE_TEACHER, $teacher->role);
        $this->assertNotNull($teacher->school_id, 'a teacher needs a workspace of their own (DESIGN §29.13.1)');
        $this->assertSame($schools + 1, School::query()->count());
        $this->assertSame('ครูเพิ่มโดยผู้ดูแล', $teacher->school->name);
        $this->assertSame($this->admin->id, $teacher->approved_by, 'added as active = approved by this admin');

        $this->asUser($teacher)->postJson('/api/v1/classrooms', [
            'name' => 'ป.5/1', 'grade_level' => 5, 'academic_year' => 2569,
        ])->assertCreated();
    }

    public function test_admin_adds_another_admin_without_a_workspace(): void
    {
        $schools = School::query()->count();

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('create'), data: [
                'name' => 'ผู้ดูแลคนที่สอง',
                'role' => User::ROLE_ADMIN,
                'email' => 'second-admin@example.com',
                'status' => User::STATUS_ACTIVE,
                'password' => 'secret1234',
            ])
            ->assertHasNoActionErrors();

        $admin = User::query()->where('email', 'second-admin@example.com')->sole();
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertNull($admin->school_id);
        $this->assertSame($schools, School::query()->count());
    }

    public function test_a_pending_teacher_added_by_an_admin_still_waits_for_approval(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('create'), data: [
                'name' => 'ครูรออนุมัติ',
                'role' => User::ROLE_TEACHER,
                'email' => 'pending-teacher@example.com',
                'status' => User::STATUS_PENDING,
                'password' => 'secret1234',
            ])
            ->assertHasNoActionErrors();

        $teacher = User::query()->where('email', 'pending-teacher@example.com')->sole();
        $this->assertSame(User::STATUS_PENDING, $teacher->status);
        $this->assertNull($teacher->approved_by);
        $this->assertNotNull($teacher->school_id);
    }

    public function test_the_create_form_checks_its_fields(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('create'), data: [
                'name' => '',
                'role' => User::ROLE_TEACHER,
                'email' => $this->admin->email,
                'status' => User::STATUS_ACTIVE,
                'password' => 'short',
            ])
            ->assertHasActionErrors(['name', 'email', 'password']);
    }

    public function test_an_admin_turned_into_a_teacher_gets_a_workspace(): void
    {
        $other = User::factory()->admin()->create(['name' => 'แอดมินที่จะเป็นครู']);
        $this->assertNull($other->school_id);

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($other), data: ['role' => User::ROLE_TEACHER])
            ->assertHasNoActionErrors();

        $other->refresh();
        $this->assertSame(User::ROLE_TEACHER, $other->role);
        $this->assertNotNull($other->school_id);
    }

    public function test_teachers_cannot_open_the_panel(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->get('/admin/users')->assertForbidden();
    }
}
