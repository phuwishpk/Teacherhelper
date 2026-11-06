<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/**
 * DESIGN §29.7 in one Plesk Scheduled Task "Run Now": drop every table, run
 * all migrations and the base seeders (DatabaseSeeder: skills, the first
 * workspace, the admin from ADMIN_*), then DemoSeeder (two standalone
 * teachers, a classroom of fictional students, DESIGN §29.13.5), then clear
 * the caches. `.env`, APP_KEY, QR_SIGNING_KEY and the stored files stay.
 *
 * It refuses without --force, and it refuses before touching anything when
 * the DEMO_* values are not set, because a wiped database with nobody to
 * sign in as helps no one. Only for an installation whose data is trial data.
 *
 *   php artisan eduvision:reset-demo --force
 */
class ResetDemoCommand extends Command
{
    protected $signature = 'eduvision:reset-demo {--force : Really drop every table (required)}';

    protected $description = 'Wipe trial data and start again as standalone teachers: migrate:fresh --seed, DemoSeeder, optimize:clear (DESIGN §29.7)';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('This drops every table. Run it as: eduvision:reset-demo --force');

            return self::FAILURE;
        }

        $missing = array_keys(array_filter([
            'DEMO_TEACHER_EMAIL' => blank(config('eduvision.demo.teacher_email')),
            'DEMO_TEACHER_PASSWORD' => blank(config('eduvision.demo.teacher_password')),
            'DEMO_STUDENT_PIN' => blank(config('eduvision.demo.student_pin')),
        ]));
        if ($missing !== []) {
            $this->error('Not set in .env: '.implode(', ', $missing).'. Set them (and ADMIN_EMAIL/ADMIN_PASSWORD for the admin), run optimize:clear if the config is cached, then run this again. Nothing was changed.');

            return self::FAILURE;
        }
        if (blank(config('eduvision.admin_email')) || blank(config('eduvision.admin_password'))) {
            $this->warn('ADMIN_EMAIL/ADMIN_PASSWORD are not set: no admin will be created (existing admins are dropped with everything else).');
        }

        $this->line('== migrate:fresh --force --seed');
        if ($this->call('migrate:fresh', ['--force' => true, '--seed' => true]) !== self::SUCCESS) {
            $this->error('migrate:fresh failed. Read the output above before running anything else.');

            return self::FAILURE;
        }
        $this->line('== db:seed DemoSeeder');
        if ($this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]) !== self::SUCCESS) {
            $this->error('DemoSeeder failed; the database is fresh but has no demo accounts. Fix the cause and run db:seed --class=DemoSeeder --force.');

            return self::FAILURE;
        }
        $this->line('== optimize:clear');
        $this->call('optimize:clear');
        $this->info('reset-demo: done. Now remove ADMIN_* and DEMO_* from .env, then Run Now eduvision:post-deploy to rebuild the caches.');

        return self::SUCCESS;
    }
}
