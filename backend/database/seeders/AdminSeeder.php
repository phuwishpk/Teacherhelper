<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Console\View\Components\Warn;
use Illuminate\Database\Seeder;

/**
 * System admin (school_id NULL) for the Filament panel, from ADMIN_EMAIL and
 * ADMIN_PASSWORD in .env (config/eduvision.php). When either is empty it
 * creates no account and prints a WARN line (without an admin nobody can
 * approve a teacher in /admin). Re-running resets the password of that email
 * to the configured value.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('eduvision.admin_email');
        $password = (string) config('eduvision.admin_password');

        if ($email === '' || $password === '') {
            if ($this->command !== null) {
                (new Warn($this->command->getOutput()))->render(
                    'AdminSeeder: ADMIN_EMAIL / ADMIN_PASSWORD are not set in .env, so no admin was created '
                    .'(nobody can sign in to /admin to approve teachers). Set both, then run '
                    .'`php artisan db:seed --class=AdminSeeder`.'
                );
            }

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
