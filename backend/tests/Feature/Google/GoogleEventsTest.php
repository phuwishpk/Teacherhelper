<?php

namespace Tests\Feature\Google;

use App\Domain\Accounts\AccountDeletion;
use App\Domain\Auth\Google\GoogleCerts;
use App\Domain\Google\GoogleAccessTokens;
use App\Domain\Google\GoogleEvents;
use App\Domain\Google\GoogleScopes;
use App\Filament\Resources\GoogleEvents\Pages\ManageGoogleEvents;
use App\Filament\Resources\Students\Pages\ManageStudents;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\GoogleAccount;
use App\Models\GoogleEvent;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Auth\GoogleSignInFixtures;
use Tests\TestCase;

/**
 * DESIGN §32: what went wrong with Google, per account, for the admin.
 * The e-mail is stored in google_events (30 days), never in the log file.
 */
class GoogleEventsTest extends TestCase
{
    use GoogleSignInFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->configureSignIn();
        Http::fake([GoogleCerts::URL => fn () => Http::response(['keys' => [self::rsaKey()['jwk']]], 200, ['Cache-Control' => 'public, max-age=19800'])]);
        $this->admin = $this->makeAdmin();
    }

    public function test_an_unknown_student_is_recorded_with_the_google_email_only_in_the_table(): void
    {
        $this->captureSignInLog();

        $this->signIn(['sub' => 'nong-sub', 'email' => 'Nong@School.ac.th'], 'student')->assertNotFound();

        $event = GoogleEvent::query()->sole();
        $this->assertNull($event->user_id);
        $this->assertSame('signin', $event->flow);
        $this->assertSame('google_not_linked', $event->result);
        $this->assertSame('unknown_student', $event->detail);
        $this->assertSame('nong@school.ac.th', $event->email);
        $this->assertStringNotContainsString('nong@school.ac.th', json_encode($this->signinLog));

        $this->actingAs($this->admin);
        Livewire::test(ManageGoogleEvents::class)
            ->assertCanSeeTableRecords([$event])
            ->assertSee('บัญชี Google นี้ยังไม่ได้ผูกกับนักเรียนคนไหน')
            ->assertSee('ซิงก์รายชื่อ')
            ->assertSee('nong@school.ac.th');
    }

    public function test_a_routine_sign_in_is_not_recorded_but_a_deleted_account_is(): void
    {
        $teacher = $this->makeTeacher(null, ['email' => 'kru@school.ac.th']);
        $this->linkGoogle($teacher, 'kru-sub', 'kru@school.ac.th');

        $this->signIn(['sub' => 'kru-sub', 'email' => 'kru@school.ac.th'])->assertOk();
        $this->assertSame(0, GoogleEvent::query()->count());

        app(AccountDeletion::class)->delete($this->admin, $teacher);
        $this->signIn(['sub' => 'kru-sub', 'email' => 'kru@school.ac.th'])->assertForbidden();

        $event = GoogleEvent::query()->sole();
        $this->assertSame($teacher->id, $event->user_id);
        $this->assertSame('account_not_active', $event->result);
        $this->assertSame('deleted', $event->detail);
    }

    public function test_a_dropped_classroom_connection_shows_on_the_account_page(): void
    {
        Queue::fake();
        $teacher = $this->makeTeacher(null, ['name' => 'ครูสมศรี']);
        $account = GoogleAccount::create([
            'user_id' => $teacher->id, 'google_sub' => 'sub-a', 'email' => 'kru.classroom@gmail.com',
            'encrypted_refresh_token' => 'refresh-token-not-real', 'scopes' => implode(' ', GoogleScopes::REQUIRED), 'connected_at' => now(),
        ]);

        GoogleAccessTokens::markNeedsReconnect($account, GoogleAccount::ERROR_INVALID_GRANT);

        $this->assertDatabaseHas('google_events', ['user_id' => $teacher->id, 'flow' => 'classroom', 'result' => 'needs_reconnect', 'detail' => 'invalid_grant']);

        $this->actingAs($this->admin);
        Livewire::test(ManageUsers::class)
            ->assertSee('Classroom ต้องเชื่อมใหม่')
            ->mountAction(TestAction::make('view')->table($teacher))
            ->assertMountedActionModalSee(['Google Classroom', 'kru.classroom@gmail.com', 'ต้องเชื่อมใหม่', 'การเชื่อม Classroom หลุด']);
    }

    public function test_the_student_page_opens_the_account_with_its_google_link(): void
    {
        $student = $this->enrollStudent($this->makeClassroom($this->makeTeacher(), ['name' => 'ม.1/2']), 1, 'เด็กหญิงทดสอบ')['student'];
        $this->linkGoogle($student, 'kid-sub', 'kid@school.ac.th', 'classroom_roster');

        $this->actingAs($this->admin);
        Livewire::test(ManageStudents::class)
            ->mountAction(TestAction::make('view')->table($student))
            ->assertMountedActionModalSee(['ม.1/2', 'เชื่อมแล้ว', 'kid@school.ac.th', 'จับคู่จากรายชื่อ Classroom'])
            ->assertMountedActionModalDontSee('สิทธิ์ที่ยังไม่ได้');
    }

    public function test_rows_older_than_30_days_are_purged(): void
    {
        GoogleEvents::record('signin', 'login', 'google_not_linked', null, 'unknown_student', 'old@example.test');
        GoogleEvents::record('signin', 'login', 'google_not_linked', null, 'unknown_student', 'new@example.test');
        GoogleEvent::query()->where('email', 'old@example.test')->update(['created_at' => now()->subDays(31)]);

        $this->artisan('eduvision:purge-images')->assertSuccessful();

        $this->assertSame(['new@example.test'], GoogleEvent::query()->pluck('email')->all());
    }

    public function test_only_admins_see_the_page(): void
    {
        $teacher = $this->makeTeacher();

        $this->assertTrue($this->admin->can('viewAny', GoogleEvent::class));
        $this->assertFalse($teacher->can('viewAny', GoogleEvent::class));
        $this->actingAs($this->admin)->get('/admin/google-events')->assertOk();
    }

    /** @param  array<string, mixed>  $claims */
    private function signIn(array $claims, string $intent = 'staff'): TestResponse
    {
        $this->forgetGuards();

        return $this->withoutToken()->postJson('/api/v1/auth/google', ['id_token' => $this->idToken($claims), 'intent' => $intent]);
    }
}
