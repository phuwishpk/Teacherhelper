<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** DESIGN §29.1: the teacher edits their own name, school name and password. */
class TeacherProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_changes_their_name_and_school_name(): void
    {
        $teacher = $this->makeTeacher();
        $colleague = $this->makeTeacher($teacher->school);
        $original = $teacher->school->name;

        $this->asUser($teacher)->patchJson('/api/v1/me', ['name' => '  ครูสมศรี ใจดี ', 'school_name' => ' โรงเรียนบ้านหนองน้ำใส '])
            ->assertOk()
            ->assertJsonPath('data.name', 'ครูสมศรี ใจดี')
            ->assertJsonPath('data.school.name', 'โรงเรียนบ้านหนองน้ำใส');

        $this->asUser($teacher)->getJson('/api/v1/me')->assertJsonPath('data.school.name', 'โรงเรียนบ้านหนองน้ำใส');
        // Only this teacher's text: the school row and the colleague are untouched.
        $this->asUser($colleague)->getJson('/api/v1/me')->assertJsonPath('data.school.name', $original);
        $this->assertSame($original, $teacher->school->fresh()->name);

        // Empty clears it again; the name alone can change too.
        $this->asUser($teacher)->patchJson('/api/v1/me', ['school_name' => ''])->assertOk()->assertJsonPath('data.school.name', $original);
        $this->asUser($teacher)->patchJson('/api/v1/me', ['name' => ' '])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->asUser($teacher)->patchJson('/api/v1/me', ['school_name' => str_repeat('ก', 151)])->assertStatus(422)->assertJsonValidationErrors(['school_name']);
    }

    public function test_a_teacher_changes_their_password_with_the_current_one(): void
    {
        $teacher = $this->makeTeacher(null, ['password' => 'old-password-1']);
        $here = $this->tokenFor($teacher);
        $elsewhere = $this->tokenFor($teacher);

        $this->withToken($here)->putJson('/api/v1/me/password', ['password' => 'new-password-2'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->withToken($here)->putJson('/api/v1/me/password', ['password' => 'new-password-2', 'current_password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->withToken($here)->putJson('/api/v1/me/password', ['password' => 'short', 'current_password' => 'old-password-1'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->withToken($here)->putJson('/api/v1/me/password', ['password' => 'new-password-2', 'current_password' => 'old-password-1'])
            ->assertNoContent();

        $this->assertTrue(Hash::check('new-password-2', $teacher->fresh()->password));
        $this->forgetGuards();
        $this->withToken($here)->getJson('/api/v1/me')->assertOk();
        $this->forgetGuards();
        $this->withToken($elsewhere)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_teacher_without_a_password_sets_one(): void
    {
        $teacher = $this->makeTeacher();
        $teacher->forceFill(['password' => null])->save();

        $this->asUser($teacher)->putJson('/api/v1/me/password', ['password' => 'first-password'])->assertNoContent();
        $this->assertTrue(Hash::check('first-password', $teacher->fresh()->password));
    }

    public function test_students_cannot_use_the_teacher_profile_routes(): void
    {
        $student = $this->enrollStudent($this->makeClassroom($this->makeTeacher()))['student'];

        $this->asUser($student, ['student'])->patchJson('/api/v1/me', ['name' => 'x'])->assertForbidden();
        $this->asUser($student, ['student'])->putJson('/api/v1/me/password', ['password' => 'whatever-1'])->assertForbidden();
        $this->asGuest()->patchJson('/api/v1/me', ['name' => 'x'])->assertUnauthorized();
    }
}
