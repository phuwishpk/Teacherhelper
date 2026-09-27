<?php

namespace Tests\Feature\Console;

use App\Models\Skill;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_seeder_creates_an_active_admin_from_config(): void
    {
        config(['eduvision.admin_email' => 'admin@example.com', 'eduvision.admin_password' => 'admin-secret-1']);

        $this->seed(AdminSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('active', $admin->status);
        $this->assertNull($admin->school_id);
        $this->assertTrue(Hash::check('admin-secret-1', $admin->password));
        $this->assertTrue($admin->canAccessPanel(filament()->getPanel('admin')));

        // Re-seeding with a new password rotates it instead of failing on the unique email.
        config(['eduvision.admin_password' => 'rotated-secret-2']);
        $this->seed(AdminSeeder::class);
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
        $this->assertTrue(Hash::check('rotated-secret-2', $admin->fresh()->password));
    }

    public function test_admin_seeder_does_nothing_without_credentials(): void
    {
        config(['eduvision.admin_email' => '', 'eduvision.admin_password' => '']);

        $this->seed(AdminSeeder::class);

        $this->assertSame(0, User::query()->count());
    }

    public function test_admin_seeder_warns_when_it_skips(): void
    {
        config(['eduvision.admin_email' => 'admin@example.com', 'eduvision.admin_password' => '']);

        $this->artisan('db:seed', ['--class' => AdminSeeder::class, '--no-interaction' => true])
            ->expectsOutputToContain('ADMIN_EMAIL / ADMIN_PASSWORD are not set in .env, so no admin was created')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    public function test_the_database_seeder_runs_end_to_end(): void
    {
        config(['eduvision.admin_email' => 'admin@example.com', 'eduvision.admin_password' => 'admin-secret-1']);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('schools', ['teacher_join_code' => config('eduvision.seed_teacher_join_code')]);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'admin']);
        $this->assertSame(13, Skill::query()->count());
    }
}
