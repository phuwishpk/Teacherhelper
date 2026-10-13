<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The one-shot Plesk "Run Now" commands of docs/HOSTING.md §4.7 and §5. */
class MaintenanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_demo_refuses_without_force(): void
    {
        $teacher = $this->makeTeacher();

        $this->artisan('eduvision:reset-demo')
            ->expectsOutputToContain('--force')
            ->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
    }

    public function test_reset_demo_refuses_before_wiping_when_the_demo_values_are_missing(): void
    {
        $teacher = $this->makeTeacher();
        config(['eduvision.demo.teacher_email' => 'teacher@example.com', 'eduvision.demo.teacher_password' => '', 'eduvision.demo.student_pin' => '']);

        $this->artisan('eduvision:reset-demo', ['--force' => true])
            ->expectsOutputToContain('DEMO_TEACHER_PASSWORD, DEMO_STUDENT_PIN')
            ->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
    }

    public function test_post_deploy_runs_the_migrations(): void
    {
        $this->artisan('eduvision:post-deploy', ['--no-cache' => true])
            ->expectsOutputToContain('migrations done')
            ->assertSuccessful();
    }
}
