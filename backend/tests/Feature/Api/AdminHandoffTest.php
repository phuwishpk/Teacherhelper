<?php

namespace Tests\Feature\Api;

use App\Domain\Auth\AdminHandoffs;
use App\Http\Controllers\AdminHandoffLinkController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The unified login of the app (DESIGN §7.4): admins sign in through
 * POST /auth/teacher/login, get a token that opens only /me, logout and
 * POST /auth/admin-handoff, and reach the Filament panel through a
 * one-time link GET /admin/handoff/{token}.
 */
class AdminHandoffTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin(['email' => 'admin@example.com', 'password' => 'secret1234']);
    }

    private function adminToken(): string
    {
        return $this->postJson('/api/v1/auth/teacher/login', ['email' => 'admin@example.com', 'password' => 'secret1234'])
            ->assertOk()
            ->json('token');
    }

    /** A fresh handoff link of the admin, issued through the API. */
    private function handoffUrl(?string $token = null): string
    {
        $this->forgetGuards();

        return $this->withToken($token ?? $this->adminToken())
            ->postJson('/api/v1/auth/admin-handoff')
            ->assertOk()
            ->json('data.url');
    }

    /** Opens $url like a browser without a session of its own. */
    private function visit(string $url): TestResponse
    {
        Auth::guard('web')->logout();
        $this->forgetGuards();
        $this->flushSession();

        return $this->withoutToken()->get(parse_url($url, PHP_URL_PATH));
    }

    private function assertRefused(TestResponse $response): void
    {
        $response->assertRedirect('/admin/login')
            ->assertSessionHas(AdminHandoffLinkController::ERROR_KEY)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertGuest('web');
    }

    public function test_an_admin_logs_in_through_the_teacher_endpoint_and_gets_an_admin_token(): void
    {
        $response = $this->postJson('/api/v1/auth/teacher/login', ['email' => 'admin@example.com', 'password' => 'secret1234'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.school', null);
        $this->assertNotEmpty($response->json('token'));

        $token = $this->admin->tokens()->firstOrFail();
        $this->assertSame(['admin'], $token->abilities);
        $this->assertTrue($token->expires_at->between(now()->addHours(23), now()->addHours(25)));

        $this->withToken($response->json('token'))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_a_teacher_still_gets_a_teacher_token(): void
    {
        $teacher = $this->makeTeacher(null, ['email' => 'teacher@example.com', 'password' => 'secret1234']);

        $this->postJson('/api/v1/auth/teacher/login', ['email' => 'teacher@example.com', 'password' => 'secret1234'])
            ->assertOk()
            ->assertJsonPath('user.role', 'teacher');

        $this->assertSame(['teacher'], $teacher->tokens()->firstOrFail()->abilities);
    }

    public function test_a_wrong_password_or_a_disabled_admin_cannot_log_in(): void
    {
        $this->postJson('/api/v1/auth/teacher/login', ['email' => 'admin@example.com', 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials');

        $this->admin->update(['status' => User::STATUS_DISABLED]);
        $this->postJson('/api/v1/auth/teacher/login', ['email' => 'admin@example.com', 'password' => 'secret1234'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_not_active');
        $this->assertSame(0, $this->admin->tokens()->count());
    }

    public function test_students_cannot_log_in_through_the_teacher_endpoint(): void
    {
        User::factory()->create(['role' => User::ROLE_STUDENT, 'email' => 'student@example.com', 'password' => 'secret1234']);

        $this->postJson('/api/v1/auth/teacher/login', ['email' => 'student@example.com', 'password' => 'secret1234'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_the_admin_token_opens_no_teacher_or_student_route(): void
    {
        $token = $this->adminToken();

        foreach ([['GET', 'classrooms'], ['POST', 'classrooms'], ['GET', 'me/ai-key'], ['GET', 'assignments'], ['GET', 'student/results'], ['GET', 'student/grades'], ['GET', 'ml/models/active'], ['POST', 'devices']] as [$method, $uri]) {
            $this->forgetGuards();
            $this->withToken($token)->json($method, '/api/v1/'.$uri)
                ->assertStatus(403)
                ->assertJsonPath('code', 'forbidden');
        }

        $this->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_the_handoff_issues_a_one_minute_link_stored_only_as_a_hash(): void
    {
        $this->freezeSecond();
        $response = $this->withToken($this->adminToken())->postJson('/api/v1/auth/admin-handoff')
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_at']])
            ->assertJsonPath('data.expires_at', now()->addSeconds(60)->utc()->toIso8601ZuluString());

        $url = $response->json('data.url');
        $this->assertMatchesRegularExpression('#/admin/handoff/[a-f0-9]{48}$#', $url);
        $token = basename($url);

        $keys = DB::table('cache')->pluck('key')->implode(' ');
        $this->assertStringNotContainsString($token, $keys);
        $this->assertStringContainsString(hash('sha256', $token), $keys);
    }

    public function test_the_link_signs_the_admin_into_the_panel_once(): void
    {
        $url = $this->handoffUrl();

        $this->visit($url)
            ->assertRedirect('/admin')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSessionMissing(AdminHandoffLinkController::ERROR_KEY);
        $this->assertAuthenticatedAs($this->admin, 'web');
        $this->get('/admin')->assertOk();

        $this->assertRefused($this->visit($url));
    }

    public function test_an_expired_link_fails(): void
    {
        $url = $this->handoffUrl();

        $this->travel(AdminHandoffs::TTL_SECONDS + 1)->seconds();

        $this->assertRefused($this->visit($url));
    }

    public function test_an_unknown_or_malformed_link_fails(): void
    {
        $this->assertRefused($this->visit('/admin/handoff/'.str_repeat('a', 48)));
        $this->assertRefused($this->visit('/admin/handoff/not-a-token'));
    }

    public function test_a_link_of_an_admin_disabled_meanwhile_fails(): void
    {
        $url = $this->handoffUrl();
        $this->admin->update(['status' => User::STATUS_DISABLED]);

        $this->assertRefused($this->visit($url));
    }

    public function test_a_link_bound_to_an_account_that_is_no_admin_fails(): void
    {
        $teacher = $this->makeTeacher();
        $token = app(AdminHandoffs::class)->issue($teacher)['token'];

        $this->assertRefused($this->visit(route('admin.handoff', ['token' => $token])));
    }

    public function test_teachers_students_and_disabled_admins_cannot_request_a_link(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->asUser($this->makeTeacher())->postJson('/api/v1/auth/admin-handoff')->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->asUser($student)->postJson('/api/v1/auth/admin-handoff')->assertStatus(403)->assertJsonPath('code', 'forbidden');
        // A teacher token that somehow carries `admin`, or an admin token without it.
        $this->asUser($this->makeTeacher(), ['admin'])->postJson('/api/v1/auth/admin-handoff')->assertStatus(403);
        $this->asUser($this->admin, ['teacher'])->postJson('/api/v1/auth/admin-handoff')->assertStatus(403);
        $this->asGuest()->postJson('/api/v1/auth/admin-handoff')->assertUnauthorized();

        $token = $this->adminToken();
        $this->admin->update(['status' => User::STATUS_DISABLED]);
        $this->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/admin-handoff')
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_not_active');
        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%admin:handoff:%')->count());
    }

    public function test_the_handoff_endpoint_is_throttled_per_admin(): void
    {
        $token = $this->adminToken();
        for ($i = 0; $i < 10; $i++) {
            $this->forgetGuards();
            $this->withToken($token)->postJson('/api/v1/auth/admin-handoff')->assertOk();
        }
        $this->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/admin-handoff')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_the_link_is_throttled_per_address(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->visit('/admin/handoff/'.str_repeat('b', 48))->assertRedirect('/admin/login');
        }
        $this->visit('/admin/handoff/'.str_repeat('b', 48))->assertStatus(429);
    }

    public function test_the_login_page_points_to_the_app_and_shows_why_a_link_failed(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('เข้าสู่ระบบจากแอป Krucheck ได้ด้วยบัญชีเดียวกัน')
            ->assertDontSee('ลิงก์เข้าสู่ระบบนี้ใช้ไม่ได้แล้ว');

        $this->visit('/admin/handoff/'.str_repeat('c', 48));
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('ลิงก์เข้าสู่ระบบนี้ใช้ไม่ได้แล้ว');
    }
}
