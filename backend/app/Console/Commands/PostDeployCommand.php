<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Everything docs/HOSTING.md §5 asks for after "Pull Updates → Deploy", in one
 * Plesk Scheduled Task "Run Now" (the task cannot be given several commands,
 * and there is no SSH): pending migrations, then the config/route/view caches
 * rebuilt so the new code is read. Nothing here is interactive, so it also
 * suits `APP_ENV=production`.
 *
 *   php artisan eduvision:post-deploy
 *   php artisan eduvision:post-deploy --no-cache   # migrations only (tests)
 */
class PostDeployCommand extends Command
{
    protected $signature = 'eduvision:post-deploy {--no-cache : Run the migrations but leave the caches alone}';

    protected $description = 'After a Plesk deploy: migrate --force, then rebuild the config, route, view and Filament caches';

    public function handle(): int
    {
        $this->line('== migrate --force');
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('migrate failed: the caches were left as they were. Read the output above, do not run again blindly.');

            return self::FAILURE;
        }

        if ($this->option('no-cache')) {
            $this->info('post-deploy: migrations done, caches untouched (--no-cache)');

            return self::SUCCESS;
        }

        foreach (['optimize:clear', 'optimize', 'filament:optimize'] as $command) {
            $this->line("== {$command}");
            if ($this->call($command) !== self::SUCCESS) {
                $this->error("{$command} failed; run it again from the task once the cause is fixed.");

                return self::FAILURE;
            }
        }
        $this->info('post-deploy: done. Check /api/v1/health and open /admin once.');

        return self::SUCCESS;
    }
}
