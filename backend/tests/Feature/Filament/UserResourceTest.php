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

    public function test_teachers_cannot_open_the_panel(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->get('/admin/users')->assertForbidden();
    }
}
