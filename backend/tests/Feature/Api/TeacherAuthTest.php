<?php

namespace Tests\Feature\Api;

use App\Models\School;
use App\Models\User;
use Database\Seeders\SchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherAuthTest extends TestCase
{
    use RefreshDatabase;

    private const JOIN_CODE = 'DEMO2569';

    protected function setUp(): void
    {
        parent::setUp();

        config(['eduvision.seed_teacher_join_code' => self::JOIN_CODE]);
        $this->seed(SchoolSeeder::class);
    }

    /**
     * @return array<string, string>
     */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'school_code' => self::JOIN_CODE,
            'name' => 'ครูทดสอบ',
            'email' => 't1@example.com',
            'password' => 'secret1234',
        ], $overrides);
    }

    public function test_register_with_valid_school_code_creates_an_active_teacher(): void
    {
        $response = $this->postJson('/api/v1/auth/teacher/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('user.name', 'ครูทดสอบ')
            ->assertJsonPath('user.email', 't1@example.com')
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('user.status', 'active') // M0 stub, see TeacherAuthController::register
            ->assertJsonPath('user.school.name', 'โรงเรียนสาธิต EduVision')
            ->assertJsonMissingPath('user.password');

        $school = School::query()->where('teacher_join_code', self::JOIN_CODE)->firstOrFail();
        $this->assertDatabaseHas('users', [
            'id' => $response->json('user.id'),
            'school_id' => $school->id,
            'role' => 'teacher',
            'status' => 'active',
        ]);
    }

    public function test_register_with_wrong_school_code_is_rejected_with_a_code(): void
    {
        $this->postJson('/api/v1/auth/teacher/register', $this->registration(['school_code' => 'WRONG123']))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors', 'code'])
            ->assertJsonPath('code', 'school_code_invalid');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_validates_the_body(): void
    {
        $this->postJson('/api/v1/auth/teacher/register', [
            'school_code' => self::JOIN_CODE,
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        $this->postJson('/api/v1/auth/teacher/register', $this->registration())->assertCreated();

        $this->postJson('/api/v1/auth/teacher/register', $this->registration(['name' => 'ครูซ้ำ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_returns_a_teacher_token_and_the_user(): void
    {
        $this->postJson('/api/v1/auth/teacher/register', $this->registration())->assertCreated();

        $response = $this->postJson('/api/v1/auth/teacher/login', [
            'email' => 't1@example.com',
            'password' => 'secret1234',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'status', 'school']])
            ->assertJsonPath('user.name', 'ครูทดสอบ');

        $this->assertNotEmpty($response->json('token'));

        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'app', // device_name omitted -> default
            'tokenable_id' => $response->json('user.id'),
        ]);

        $token = User::query()->firstOrFail()->tokens()->firstOrFail();
        $this->assertSame(['teacher'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_login_uses_device_name_when_given(): void
    {
        $this->postJson('/api/v1/auth/teacher/register', $this->registration())->assertCreated();

        $this->postJson('/api/v1/auth/teacher/login', [
            'email' => 't1@example.com',
            'password' => 'secret1234',
            'device_name' => 'curl',
        ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'curl']);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/teacher/register', $this->registration())->assertCreated();

        $this->postJson('/api/v1/auth/teacher/login', [
            'email' => 't1@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonMissingPath('token');
    }

    public function test_login_with_unknown_email_is_rejected_the_same_way(): void
    {
        $this->postJson('/api/v1/auth/teacher/login', [
            'email' => 'nobody@example.com',
            'password' => 'secret1234',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_login_requires_an_active_account(): void
    {
        $school = School::query()->firstOrFail();
        User::factory()->teacher($school)->pending()->create([
            'email' => 'pending@example.com',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/v1/auth/teacher/login', [
            'email' => 'pending@example.com',
            'password' => 'secret1234',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_not_active');
    }

    public function test_me_returns_the_authenticated_user_wrapped_in_data(): void
    {
        $this->postJson('/api/v1/auth/teacher/register', $this->registration())->assertCreated();
        $token = $this->postJson('/api/v1/auth/teacher/login', [
            'email' => 't1@example.com',
            'password' => 'secret1234',
        ])->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'ครูทดสอบ')
            ->assertJsonPath('data.role', 'teacher')
            ->assertJsonPath('data.school.name', 'โรงเรียนสาธิต EduVision');
    }

    public function test_me_without_a_token_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $this->postJson('/api/v1/auth/teacher/register', $this->registration())->assertCreated();
        $login = fn (string $device) => $this->postJson('/api/v1/auth/teacher/login', [
            'email' => 't1@example.com',
            'password' => 'secret1234',
            'device_name' => $device,
        ])->json('token');

        $phone = $login('phone');
        $tablet = $login('tablet');

        $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();

        // The RequestGuard caches the resolved user between requests of one test;
        // drop it so each request below re-checks its bearer token like a real client.
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_auth_endpoints_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/teacher/login', ['email' => 'x@example.com', 'password' => 'bad-password'])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/teacher/login', ['email' => 'x@example.com', 'password' => 'bad-password'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'errors', 'code'])
            ->assertJsonPath('code', 'too_many_requests')
            ->assertJsonMissingPath('exception');
    }

    public function test_me_without_a_token_and_without_an_accept_header_is_still_a_json_401(): void
    {
        $this->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }
}
