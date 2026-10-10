<?php

namespace Tests\Feature\Api;

use App\Domain\Classrooms\StudentEnroller;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §29.10: students sign in with a username and a password; the
 * initial password 123456 must be replaced before anything else opens.
 */
class StudentPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
    }

    /** A brand-new student, as the teacher's "add students" leaves them. */
    private function newStudent(int $number = 1, ?string $code = null): User
    {
        return app(StudentEnroller::class)->enroll($this->classroom, [
            ['name' => "นักเรียน {$number}", 'student_number' => $number] + ($code === null ? [] : ['student_code' => $code]),
        ])[0]['student'];
    }

    private function login(string $username, string $password): string
    {
        return $this->asGuest()->postJson('/api/v1/auth/student/login', ['username' => $username, 'password' => $password])
            ->assertOk()->json('token');
    }

    public function test_a_new_student_gets_a_username_and_the_initial_password(): void
    {
        $plain = $this->newStudent(1);
        $coded = $this->newStudent(2, '65012');
        $sameCode = app(StudentEnroller::class)->enroll($this->makeClassroom($this->makeTeacher()), [
            ['name' => 'อีกโรงเรียน', 'student_number' => 1, 'student_code' => '65012'],
        ])[0]['student'];

        $this->assertMatchesRegularExpression('/^s\d{7}$/', $plain->username);
        $this->assertSame('65012', $coded->username);
        // The same student code in another school: the username is taken, so one is generated.
        $this->assertMatchesRegularExpression('/^s\d{7}$/', $sameCode->username);

        $this->asGuest()->postJson('/api/v1/auth/student/login', ['username' => ' '.strtoupper($plain->username).' ', 'password' => '123456'])
            ->assertOk()
            ->assertJsonPath('user.username', $plain->username)
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_nothing_opens_until_the_initial_password_is_replaced(): void
    {
        $student = $this->newStudent();
        $token = $this->login($student->username, '123456');
        $other = $this->login($student->username, '123456');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->withToken($token)->getJson('/api/v1/student/overview')
            ->assertForbidden()->assertJsonPath('code', 'password_change_required');

        foreach (['12345' => 'password', '123456' => 'password', '' => 'password'] as $bad => $field) {
            $this->withToken($token)->putJson('/api/v1/student/password', ['password' => (string) $bad])
                ->assertStatus(422)->assertJsonValidationErrors([$field]);
        }

        $this->withToken($token)->putJson('/api/v1/student/password', ['password' => 'ปลาทอง99'])
            ->assertOk()->assertJsonPath('user.must_change_password', false);

        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/student/overview')->assertOk();
        // The other device is signed out, the initial password is gone, the new one works.
        $this->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/me')->assertUnauthorized();
        $this->asGuest()->postJson('/api/v1/auth/student/login', ['username' => $student->username, 'password' => '123456'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        $this->login($student->username, 'ปลาทอง99');
    }

    public function test_a_later_change_needs_the_current_password(): void
    {
        ['student' => $student, 'pin' => $password, 'username' => $username] = $this->enrollStudent($this->classroom);
        $token = $this->login($username, $password);

        $this->withToken($token)->putJson('/api/v1/student/password', ['password' => 'new-secret'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->withToken($token)->putJson('/api/v1/student/password', ['password' => 'new-secret', 'current_password' => 'wrong-one'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->withToken($token)->putJson('/api/v1/student/password', ['password' => 'new-secret', 'current_password' => $password])->assertOk();

        $this->login($username, 'new-secret');
        $this->asUser($this->teacher)->putJson('/api/v1/student/password', ['password' => 'new-secret'])->assertForbidden();
        $this->assertFalse((bool) $student->credential()->value('must_change_password'));
    }

    public function test_the_teachers_reset_brings_the_initial_password_back(): void
    {
        ['student' => $student, 'pin' => $password, 'username' => $username] = $this->enrollStudent($this->classroom);
        $token = $this->login($username, $password);

        $this->asUser($this->teacher)->postJson("/api/v1/students/{$student->id}/pin")
            ->assertOk()->assertJsonPath('pin', '123456')->assertJsonPath('username', $username);

        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->asGuest()->postJson('/api/v1/auth/student/login', ['username' => $username, 'password' => $password])->assertStatus(422);
        $this->asGuest()->postJson('/api/v1/auth/student/login', ['username' => $username, 'password' => '123456'])
            ->assertOk()->assertJsonPath('user.must_change_password', true);
    }

    public function test_the_teacher_sees_and_changes_a_username(): void
    {
        ['student' => $student, 'pin' => $password] = $this->enrollStudent($this->classroom, 1);
        $taken = $this->enrollStudent($this->classroom, 2)['username'];
        $url = "/api/v1/classrooms/{$this->classroom->id}/students/{$student->id}";

        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/roster")
            ->assertOk()->assertJsonPath('data.0.username', $student->username);

        $this->asUser($this->teacher)->patchJson($url, ['username' => $taken])->assertStatus(422)->assertJsonValidationErrors(['username']);
        $this->asUser($this->teacher)->patchJson($url, ['username' => 'ก้อง'])->assertStatus(422)->assertJsonValidationErrors(['username']);
        $this->asUser($this->teacher)->patchJson($url, ['username' => 'ab'])->assertStatus(422)->assertJsonValidationErrors(['username']);
        $this->asUser($this->teacher)->patchJson($url, ['username' => ' Kong.P5 '])
            ->assertOk()->assertJsonPath('data.username', 'kong.p5')->assertJsonPath('data.student_number', 1);

        $this->login('kong.p5', $password);
        // The number alone still works as before.
        $this->asUser($this->teacher)->patchJson($url, ['student_number' => 9])->assertOk()->assertJsonPath('data.student_number', 9);
    }
}
