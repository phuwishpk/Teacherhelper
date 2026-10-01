<?php

namespace Tests\Feature\Students;

use App\Models\UserGoogleIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §24.9.5: a PIN reset removes the student's Google sign-in link
 * unless the teacher asks to keep it, so a person who learned the old PIN
 * and linked their own Google account loses access with the reset.
 */
class PinResetGoogleUnlinkTest extends TestCase
{
    use RefreshDatabase;

    private function linkGoogle(int $userId): void
    {
        UserGoogleIdentity::query()->create([
            'user_id' => $userId,
            'google_sub' => 'sub-'.$userId,
            'email' => 'kid'.$userId.'@example.com',
            'name' => 'Kid',
            'linked_via' => UserGoogleIdentity::VIA_PIN_CONFIRM,
            'notice_version' => 'gsi-1',
            'linked_at' => now(),
        ]);
    }

    public function test_reset_removes_the_google_link_by_default(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $student = $this->enrollStudent($classroom)['student'];
        $this->linkGoogle($student->id);

        $this->asUser($teacher)->postJson("/api/v1/students/{$student->id}/pin")
            ->assertOk()
            ->assertJsonPath('google_unlinked', true)
            ->assertJsonStructure(['student_id', 'pin', 'google_unlinked']);

        $this->assertDatabaseMissing('user_google_identities', ['user_id' => $student->id]);
    }

    public function test_keep_google_leaves_the_link(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $student = $this->enrollStudent($classroom)['student'];
        $this->linkGoogle($student->id);

        $this->asUser($teacher)->postJson("/api/v1/students/{$student->id}/pin", ['keep_google' => true])
            ->assertOk()
            ->assertJsonPath('google_unlinked', false);

        $this->assertDatabaseHas('user_google_identities', ['user_id' => $student->id]);
    }

    public function test_reset_without_a_link_reports_nothing_unlinked(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $student = $this->enrollStudent($classroom)['student'];

        $this->asUser($teacher)->postJson("/api/v1/students/{$student->id}/pin")
            ->assertOk()
            ->assertJsonPath('google_unlinked', false);
    }

    public function test_keep_google_must_be_boolean(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $student = $this->enrollStudent($classroom)['student'];

        $this->asUser($teacher)->postJson("/api/v1/students/{$student->id}/pin", ['keep_google' => 'maybe'])
            ->assertStatus(422);
    }
}
