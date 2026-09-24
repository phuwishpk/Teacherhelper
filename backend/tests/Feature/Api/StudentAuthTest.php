<?php

namespace Tests\Feature\Api;

use App\Domain\Students\CredentialIssuer;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §7.4 / §9.1: QR card login, PIN fallback with lockout, token abilities.
 */
class StudentAuthTest extends TestCase
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

        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher, ['class_code' => 'ABC234']);
        ['student' => $this->student, 'pin' => $this->pin, 'qr_token' => $this->qrToken] = $this->enrollStudent($this->classroom, 7, 'เด็กชายทดสอบ');
    }

    public function test_qr_login_with_the_full_card_payload_returns_a_student_token(): void
    {
        $response = $this->postJson('/api/v1/auth/student/qr', ['qr_token' => 'EVL1.'.$this->qrToken])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'role', 'status', 'school']])
            ->assertJsonPath('user.id', $this->student->id)
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.email', null);

        $this->assertNotEmpty($response->json('token'));

        $token = $this->student->tokens()->firstOrFail();
        $this->assertSame(['student'], $token->abilities);
        $this->assertTrue($token->expires_at->between(now()->addDays(179), now()->addDays(181)));
    }

    public function test_qr_login_accepts_the_bare_token_as_the_app_sends_it(): void
    {
        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $this->qrToken])
            ->assertOk()
            ->assertJsonPath('user.id', $this->student->id);
    }

    public function test_qr_login_with_an_unknown_token_is_rejected_with_qr_invalid(): void
    {
        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => 'EVL1.'.CredentialIssuer::randomQrToken()])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors', 'code'])
            ->assertJsonPath('code', 'qr_invalid');

        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => ''])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_qr_login_of_a_disabled_student_is_refused(): void
    {
        $this->student->update(['status' => User::STATUS_DISABLED]);

        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $this->qrToken])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_not_active');
    }

    public function test_pin_login_returns_a_student_token(): void
    {
        $this->postJson('/api/v1/auth/student/pin', [
            'class_code' => 'abc234', // case and spacing are normalised
            'student_number' => 7,
            'pin' => $this->pin,
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $this->student->id);

        $this->assertSame(['student'], $this->student->tokens()->firstOrFail()->abilities);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->pin);
    }

    public function test_pin_login_validates_the_body(): void
    {
        $this->postJson('/api/v1/auth/student/pin', ['class_code' => '', 'student_number' => 'x', 'pin' => '12'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['class_code', 'student_number', 'pin']);
    }

    public function test_wrong_class_code_number_or_pin_all_fail_the_same_way(): void
    {
        foreach ([
            ['class_code' => 'ZZZ999', 'student_number' => 7, 'pin' => $this->pin],
            ['class_code' => 'ABC234', 'student_number' => 8, 'pin' => $this->pin],
            ['class_code' => 'ABC234', 'student_number' => 7, 'pin' => $this->pin === '000000' ? '111111' : '000000'],
        ] as $body) {
            $this->postJson('/api/v1/auth/student/pin', $body)
                ->assertStatus(422)
                ->assertJsonPath('code', 'invalid_credentials')
                ->assertJsonPath('message', 'รหัสห้อง เลขที่ หรือ PIN ไม่ถูกต้อง');
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_five_wrong_pins_lock_the_student_for_fifteen_minutes(): void
    {
        $wrong = fn () => $this->postJson('/api/v1/auth/student/pin', [
            'class_code' => 'ABC234', 'student_number' => 7, 'pin' => $this->wrongPin(),
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $wrong()->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
            $this->assertSame($i, $this->student->credential->fresh()->failed_pin_attempts);
        }

        // 5th failure locks
        $wrong()->assertStatus(423)->assertJsonPath('code', 'pin_locked');
        $credential = $this->student->credential->fresh();
        $this->assertNotNull($credential->locked_until);
        $this->assertTrue($credential->locked_until->between(now()->addMinutes(14), now()->addMinutes(16)));

        // While locked, even the right PIN is refused.
        $this->postJson('/api/v1/auth/student/pin', ['class_code' => 'ABC234', 'student_number' => 7, 'pin' => $this->pin])
            ->assertStatus(423)
            ->assertJsonPath('code', 'pin_locked');

        // After the lockout the right PIN works and the counters are reset.
        $this->travel(16)->minutes();
        $this->postJson('/api/v1/auth/student/pin', ['class_code' => 'ABC234', 'student_number' => 7, 'pin' => $this->pin])
            ->assertOk();
        $credential = $this->student->credential->fresh();
        $this->assertSame(0, $credential->failed_pin_attempts);
        $this->assertNull($credential->locked_until);
    }

    public function test_a_correct_pin_resets_the_failure_counter(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/student/pin', ['class_code' => 'ABC234', 'student_number' => 7, 'pin' => $this->wrongPin()])
                ->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/student/pin', ['class_code' => 'ABC234', 'student_number' => 7, 'pin' => $this->pin])->assertOk();

        $this->assertSame(0, $this->student->credential->fresh()->failed_pin_attempts);
    }

    public function test_student_auth_endpoints_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/student/qr', ['qr_token' => 'nope'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => 'nope'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_a_student_token_reaches_me_but_not_teacher_routes(): void
    {
        $token = $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $this->qrToken])->json('token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'student')
            ->assertJsonPath('data.school.id', $this->teacher->school_id);

        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/classrooms')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/classrooms/'.$this->classroom->id.'/students', ['students' => [['name' => 'x', 'student_number' => 1]]])
            ->assertForbidden();

        $this->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_reissuing_the_card_invalidates_the_old_qr_token_and_sessions(): void
    {
        $old = $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $this->qrToken])->json('token');

        $newQr = app(CredentialIssuer::class)->issueQrToken($this->student);

        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $this->qrToken])->assertStatus(422)->assertJsonPath('code', 'qr_invalid');
        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $newQr])->assertOk();

        $this->forgetGuards();
        $this->withToken($old)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_resetting_the_pin_invalidates_the_old_pin_and_sessions(): void
    {
        $old = $this->postJson('/api/v1/auth/student/pin', ['class_code' => 'ABC234', 'student_number' => 7, 'pin' => $this->pin])->json('token');

        $newPin = app(CredentialIssuer::class)->issuePin($this->student);

        $this->postJson('/api/v1/auth/student/pin', ['class_code' => 'ABC234', 'student_number' => 7, 'pin' => $this->pin])
            ->assertStatus(422);
        $this->postJson('/api/v1/auth/student/pin', ['class_code' => 'ABC234', 'student_number' => 7, 'pin' => $newPin])
            ->assertOk();

        $this->forgetGuards();
        $this->withToken($old)->getJson('/api/v1/me')->assertUnauthorized();
    }

    private function wrongPin(): string
    {
        return $this->pin === '000000' ? '111111' : '000000';
    }
}
