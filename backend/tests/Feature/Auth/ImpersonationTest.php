<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Impersonations;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §29.6, §29.14: an admin opens the web app as a teacher or student
 * through a one-time ticket, with that user's rights, a banner (the app's
 * side) and an audit log entry at both ends.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->admin = $this->makeAdmin(['name' => 'แอดมินใหญ่']);
        $this->teacher = $this->makeTeacher(null, ['name' => 'ครูสมศรี']);
        $this->makeClassroom($this->teacher);
    }

    public function test_the_panel_hands_the_admin_to_the_app_as_the_teacher(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ManageUsers::class)
            ->assertActionVisible(TestAction::make('impersonate')->table($this->teacher))
            ->assertActionHidden(TestAction::make('impersonate')->table($this->admin))
            ->callAction(TestAction::make('impersonate')->table($this->teacher))
            ->assertHasNoActionErrors()
            ->assertRedirectContains('/app/#/impersonate?ticket=');

        $ticket = app(Impersonations::class)->issueTicket($this->admin, $this->teacher);
        $this->forgetGuards();
        $response = $this->withoutToken()->postJson('/api/v1/auth/impersonate/ticket', ['ticket' => $ticket, 'device_name' => 'web'])
            ->assertOk()
            ->assertJsonPath('user.id', $this->teacher->id)
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('user.impersonated_by.id', $this->admin->id)
            ->assertJsonPath('user.impersonated_by.name', 'แอดมินใหญ่');
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame(['teacher'], $token->abilities);
        $this->assertSame('impersonation:'.$this->admin->id, $token->name);
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $token->expires_at->getTimestamp(), 5);

        // Spent.
        $this->postJson('/api/v1/auth/impersonate/ticket', ['ticket' => $ticket])->assertStatus(422)->assertJsonPath('code', 'impersonation_ticket_invalid');

        // The teacher's rights, and /me keeps saying who is behind the session.
        $this->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.impersonated_by.name', 'แอดมินใหญ่');
        $this->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/v1/classrooms')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->admin->id, 'action' => 'impersonate.start', 'target_user_id' => $this->teacher->id]);

        // Logging out ends the session and records it.
        $this->forgetGuards();
        $this->withToken($response->json('token'))->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertNull(PersonalAccessToken::findToken($response->json('token')));
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->admin->id, 'action' => 'impersonate.stop', 'target_user_id' => $this->teacher->id]);
        $this->assertSame(2, AuditLog::query()->count());

        // An ordinary login is not an impersonation.
        $this->forgetGuards();
        $this->asUser($this->teacher)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.impersonated_by', null);
    }

    public function test_only_active_teachers_and_students_are_impersonated(): void
    {
        $student = $this->enrollStudent($this->makeClassroom($this->teacher))['student'];
        $pending = $this->makeTeacher(null, ['status' => 'pending']);
        $other = $this->makeAdmin();

        $this->assertTrue($this->admin->can('impersonate', $this->teacher));
        $this->assertTrue($this->admin->can('impersonate', $student));
        $this->assertFalse($this->admin->can('impersonate', $pending));
        $this->assertFalse($this->admin->can('impersonate', $other), 'never another admin');
        $this->assertFalse($this->teacher->can('impersonate', $student));

        // A ticket for a pair that stopped being allowed is refused when redeemed.
        $ticket = app(Impersonations::class)->issueTicket($this->admin, $this->teacher);
        $this->teacher->forceFill(['status' => 'disabled'])->save();
        $this->postJson('/api/v1/auth/impersonate/ticket', ['ticket' => $ticket])->assertStatus(422)->assertJsonPath('code', 'impersonation_ticket_invalid');
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_bad_or_expired_ticket_is_refused(): void
    {
        $this->postJson('/api/v1/auth/impersonate/ticket', ['ticket' => str_repeat('0', 48)])->assertStatus(422)->assertJsonPath('code', 'impersonation_ticket_invalid');
        $this->postJson('/api/v1/auth/impersonate/ticket', ['ticket' => 'short'])->assertStatus(422)->assertJsonPath('code', 'impersonation_ticket_invalid');
        $this->postJson('/api/v1/auth/impersonate/ticket', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $ticket = app(Impersonations::class)->issueTicket($this->admin, $this->teacher);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/impersonate/ticket', ['ticket' => $ticket])->assertStatus(422)->assertJsonPath('code', 'impersonation_ticket_invalid');
    }

    public function test_the_audit_log_is_listed_in_the_panel(): void
    {
        $ticket = app(Impersonations::class)->issueTicket($this->admin, $this->teacher);
        $this->postJson('/api/v1/auth/impersonate/ticket', ['ticket' => $ticket])->assertOk();

        $this->actingAs($this->admin);
        $this->get('/admin/audit-logs')->assertOk()->assertSee('เข้าใช้แทน')->assertSee('ครูสมศรี');
        $this->actingAs($this->teacher);
        $this->get('/admin/audit-logs')->assertForbidden();
    }
}
