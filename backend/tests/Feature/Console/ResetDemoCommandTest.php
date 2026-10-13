<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

/**
 * eduvision:reset-demo runs migrate:fresh, whose VACUUM cannot run inside the
 * transaction RefreshDatabase opens, so this test migrates by hand and wipes
 * again at the end to leave the in-memory database empty for the next class.
 */
class ResetDemoCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        $this->artisan('db:wipe');
        parent::tearDown();
    }

    public function test_reset_demo_wipes_and_seeds_the_standalone_demo(): void
    {
        $this->makeTeacher(null, ['email' => 'old@example.com']);
        config([
            'eduvision.admin_email' => 'admin@example.com',
            'eduvision.admin_password' => 'admin-secret-1',
            'eduvision.demo.teacher_email' => 'teacher@example.com',
            'eduvision.demo.teacher_password' => 'teacher-secret-1',
            'eduvision.demo.student_pin' => '246810',
        ]);

        $this->artisan('eduvision:reset-demo', ['--force' => true])
            ->expectsOutputToContain('DemoSeeder: teacher teacher@example.com ready')
            ->expectsOutputToContain('reset-demo: done')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'old@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'teacher@example.com', 'role' => 'teacher', 'status' => 'active']);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'admin']);
    }
}
