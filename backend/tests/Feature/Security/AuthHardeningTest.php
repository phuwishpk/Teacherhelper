<?php

namespace Tests\Feature\Security;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DESIGN §7.4 beyond the happy paths in TeacherAuthTest / StudentAuthTest:
 * token lifetime and revocation, the PIN lockout being a property of the
 * account (not of the caller's address), the per-IP teacher throttle, and
 * credentials never stored in clear.
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
