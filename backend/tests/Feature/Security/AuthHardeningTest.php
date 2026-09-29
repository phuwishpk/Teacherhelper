<?php

namespace Tests\Feature\Security;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DESIGN §7.4 beyond the happy paths in TeacherAuthTest / StudentAuthTest:
 * token lifetime and revocation, the PIN lockout being a property of the
 * account (not of the caller's address), the per-IP teacher throttle, one
 * bucket per throttled route, and credentials never stored in clear.
 */
class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private User $student;

    private string $pin;

    private string $qrToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher(null, ['email' => 'teacher@example.com', 'password' => 'secret1234']);
        $this->classroom = $this->makeClassroom($this->teacher, ['class_code' => 'SEC234']);
        ['student' => $this->student, 'pin' => $this->pin, 'qr_token' => $this->qrToken] = $this->enrollStudent($this->classroom, 3);
    }

    public function test_an_expired_token_is_unauthenticated(): void
    {
        $expired = $this->teacher->createToken('phone', ['teacher'], now()->subMinute())->plainTextToken;

        $this->withToken($expired)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->forgetGuards();
        $this->withToken($expired)->getJson('/api/v1/classrooms')->assertUnauthorized();
    }

    public function test_tokens_issued_by_login_expire_after_the_configured_lifetime(): void
    {
        $token = $this->postJson('/api/v1/auth/teacher/login', ['email' => 'teacher@example.com', 'password' => 'secret1234'])->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->travel(31)->days();
        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_token_of_a_deleted_account_is_unauthenticated(): void
    {
        $lonely = $this->makeTeacher();
        $token = $this->tokenFor($lonely);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $lonely->tokens()->delete();
        $lonely->delete();
        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_tampered_or_malformed_bearer_is_unauthenticated_not_an_error(): void
    {
        $token = $this->tokenFor($this->teacher);
        [$id, $secret] = explode('|', $token, 2);

        foreach ([
            $id.'|'.strrev($secret),
            ($id + 1).'|'.$secret,
            $id.'|',
            $id,
            'not a token',
            str_repeat('a', 5000),
            '',
        ] as $bad) {
            $this->forgetGuards();
            $this->withToken($bad)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_the_pin_lockout_belongs_to_the_account_not_to_the_address(): void
    {
        $wrong = $this->pin === '000000' ? '111111' : '000000';
        $body = fn (string $pin) => ['class_code' => 'SEC234', 'student_number' => 3, 'pin' => $pin];

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->postJson('/api/v1/auth/student/pin', $body($wrong));
        }

        // Another address, the right PIN: still locked (the counter lives on the credential row).
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/api/v1/auth/student/pin', $body($this->pin))
            ->assertStatus(423)
            ->assertJsonPath('code', 'pin_locked');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The QR card is a separate credential and keeps working.
        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $this->qrToken])->assertOk();
    }

    public function test_the_teacher_login_throttle_is_per_address(): void
    {
        $bad = ['email' => 'teacher@example.com', 'password' => 'wrong-password'];
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->postJson('/api/v1/auth/teacher/login', $bad)->assertStatus(422);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->postJson('/api/v1/auth/teacher/login', $bad)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_requests');

        // The teacher can still log in from their own phone.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/v1/auth/teacher/login', ['email' => 'teacher@example.com', 'password' => 'secret1234'])
            ->assertOk();
    }

    public function test_registration_is_throttled_like_login(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/teacher/register', ['school_code' => 'WRONG000', 'name' => 'x', 'email' => "t{$i}@example.com", 'password' => 'secret1234'])
                ->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/teacher/register', ['school_code' => 'WRONG000', 'name' => 'x', 'email' => 't99@example.com', 'password' => 'secret1234'])
            ->assertStatus(429);
    }

    public function test_teacher_register_and_login_share_one_bucket_per_address_on_purpose(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/teacher/login', ['email' => 'teacher@example.com', 'password' => 'wrong-password'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/teacher/register', ['school_code' => 'WRONG000', 'name' => 'x', 'email' => 'new@example.com', 'password' => 'secret1234'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }

    /**
     * Every throttle on /api/v1 is a registered named limiter. A bare
     * `throttle:N,M` keys on the user id alone, so all routes using one
     * would share a single counter per user.
     */
    public function test_every_api_throttle_is_a_registered_named_limiter(): void
    {
        $throttled = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }
                $args = explode(',', substr($middleware, strlen('throttle:')));
                $this->assertCount(1, $args, "{$route->getName()} uses a bare {$middleware}: give it a named limiter in AppServiceProvider");
                $this->assertFalse(is_numeric($args[0]), "{$route->getName()} uses a bare {$middleware}");
                $this->assertNotNull(RateLimiter::limiter($args[0]), "{$route->getName()}: limiter '{$args[0]}' is not registered");
                $throttled[$args[0]][] = $route->getName();
            }
        }

        $this->assertSame(
            ['ai-key', 'answer-key', 'appeal', 'course-extract', 'documents', 'explanation', 'google', 'page-upload', 'practice-attempt', 'practice-generate', 'student-auth', 'student-submission', 'teacher-auth'],
            collect($throttled)->keys()->sort()->values()->all(),
        );
    }

    /**
     * [role, method, uri, per-minute limit]. The ids do not exist: the
     * limiter counts the hit before the controller answers 404/422, so
     * nothing reaches Gemini.
     *
     * @return array<string, array{string, string, string, int}>
     */
    public static function throttledRoutes(): array
    {
        return [
            'ai-key' => ['teacher', 'PUT', '/api/v1/me/ai-key', 10],
            'explanation' => ['teacher', 'POST', '/api/v1/responses/999999/regenerate-explanation', 20],
            'practice-generate' => ['teacher', 'POST', '/api/v1/skills/999999/practice-items/generate', 10],
            'appeal' => ['student', 'POST', '/api/v1/student/responses/999999/appeal', 30],
            'practice-attempt' => ['student', 'POST', '/api/v1/student/practice/999999/attempts', 60],
            'page-upload' => ['teacher', 'POST', '/api/v1/assignments/999999/students/999999/pages', 60],
            'student-submission' => ['student', 'POST', '/api/v1/student/assignments/999999/submission', 10],
            'course-extract' => ['teacher', 'POST', '/api/v1/courses/extract', 10],
        ];
    }

    #[DataProvider('throttledRoutes')]
    public function test_each_throttled_route_has_its_own_bucket(string $role, string $method, string $uri, int $limit): void
    {
        $token = $this->tokenFor($role === 'teacher' ? $this->teacher : $this->student);
        $call = fn (string $m, string $u) => $this->withToken($token)->json($m, $u);

        for ($i = 1; $i <= $limit; $i++) {
            $this->assertNotSame(429, $call($method, $uri)->status(), "request {$i} of {$limit}");
        }
        $call($method, $uri)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_requests');

        // The same user's other throttled routes still answer.
        $others = 0;
        foreach (self::throttledRoutes() as $name => [$otherRole, $otherMethod, $otherUri]) {
            if ($otherRole !== $role || $otherUri === $uri) {
                continue;
            }
            $this->assertNotSame(429, $call($otherMethod, $otherUri)->status(), "{$name} shares the bucket of {$uri}");
            $others++;
        }
        $this->assertGreaterThan(0, $others);
    }

    public function test_credentials_are_stored_hashed_and_tokens_are_not_reusable_after_logout(): void
    {
        $this->assertNotSame('secret1234', $this->teacher->getAuthPassword());
        $this->assertTrue(Hash::check('secret1234', $this->teacher->getAuthPassword()));

        $credential = $this->student->credential;
        $this->assertNotNull($credential);
        $this->assertStringNotContainsString($this->pin, (string) $credential->pin_hash);
        $this->assertStringNotContainsString($this->qrToken, (string) $credential->qr_token_hash);
        $this->assertArrayNotHasKey('qr_token_hash', $credential->toArray());
        $this->assertArrayNotHasKey('pin_hash', $credential->toArray());

        $token = $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $this->qrToken])->json('token');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }
}
