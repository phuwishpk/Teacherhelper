<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * System admin (school_id NULL) for the Filament panel, from ADMIN_EMAIL and
 * ADMIN_PASSWORD in .env (config/eduvision.php). Skips silently when either is
 * empty, so `db:seed` on a server without those values creates no account.
 * Re-running resets the password of that email to the configured value.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('eduvision.admin_email');
        $password = (string) config('eduvision.admin_password');

        if ($email === '' || $password === '') {
            $this->command?->warn('AdminSeeder: ADMIN_EMAIL / ADMIN_PASSWORD not set, no admin created');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'school_id' => null,
                'role' => User::ROLE_ADMIN,
                'name' => 'ผู้ดูแลระบบ',
                'password' => $password, // hashed by the model cast
                'status' => User::STATUS_ACTIVE,
            ],
        );

        $this->command?->info("AdminSeeder: admin {$email} ready");
    }
}
