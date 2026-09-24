<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §9.1 POST /devices {fcm_token} for teachers and students.
 */
class DeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_registers_a_device_token(): void
    {
        $teacher = $this->makeTeacher();

        $this->asUser($teacher)->postJson('/api/v1/devices', ['fcm_token' => 'fcm-abc'])
            ->assertCreated()
            ->assertJsonPath('fcm_token', 'fcm-abc')
            ->assertJsonStructure(['id', 'fcm_token', 'last_seen_at']);

        $this->assertDatabaseHas('device_tokens', ['user_id' => $teacher->id, 'fcm_token' => 'fcm-abc']);
    }

    public function test_registering_the_same_token_again_refreshes_it_and_reassigns_it_to_the_current_user(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        ['student' => $student] = $this->enrollStudent($classroom);

        $this->asUser($teacher)->postJson('/api/v1/devices', ['fcm_token' => 'shared-phone'])->assertCreated();
        $this->travel(1)->hour();
        $this->asUser($student, ['student'])->postJson('/api/v1/devices', ['fcm_token' => 'shared-phone'])->assertOk();

        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseHas('device_tokens', ['user_id' => $student->id, 'fcm_token' => 'shared-phone']);
    }

    public function test_devices_requires_a_token_and_a_body(): void
    {
        $this->postJson('/api/v1/devices', ['fcm_token' => 'x'])->assertUnauthorized();

        $this->asUser($this->makeTeacher())->postJson('/api/v1/devices', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fcm_token']);
    }
}
