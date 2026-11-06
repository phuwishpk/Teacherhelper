<?php

namespace App\Console\Commands;

use App\Domain\Ops\DeployState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Everything docs/HOSTING.md §5 asks for after Plesk Git has pulled new
 * code: pending migrations, then the config/route/view caches rebuilt so
 * the new code is read. One command because a Plesk Scheduled Task runs one
 * artisan command and there is no SSH. Nothing here is interactive, so it
 * suits `APP_ENV=production`.
 *
 *   php artisan eduvision:post-deploy                 # Run Now after a deploy
 *   php artisan eduvision:post-deploy --when-changed  # every 5 minutes: acts only when the code changed
 *   php artisan eduvision:post-deploy --no-cache      # migrations only (tests)
 *
 * --when-changed compares a fingerprint of the deployed tree (DeployState)
 * with the one stored by the last run; when nothing changed it exits at once,
 * so Automatic deployment in Plesk Git plus this task every few minutes is
 * a hands-off deploy. A composer.lock that vendor/ no longer matches is
 * reported (and logged as deploy.vendor_outdated) because only the Composer
 * extension can fix that (§4.3).
 */
class PostDeployCommand extends Command
{
    protected $signature = 'eduvision:post-deploy
        {--when-changed : Do nothing unless the deployed code differs from the last run}
        {--no-cache : Run the migrations but leave the caches alone}';

    protected $description = 'After a Plesk deploy: migrate --force, then rebuild the config, route, view and Filament caches';

    public function handle(): int
    {
        $fingerprint = DeployState::fingerprint();
        if ($this->option('when-changed') && $fingerprint === DeployState::lastFingerprint()) {
            $this->line('post-deploy: unchanged since the last run, nothing to do');

            return self::SUCCESS;
        }

        $mismatches = DeployState::vendorMismatches();
        if ($mismatches !== []) {
            $this->warn('vendor/ does not match composer.lock ('.count($mismatches).' package(s), e.g. '.$mismatches[0].'): run Composer Install in Plesk (HOSTING.md §4.3). Continuing anyway.');
            Log::warning('deploy.vendor_outdated', ['packages' => array_slice($mismatches, 0, 20)]);
        }

        $this->line('== migrate --force');
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('migrate failed: the caches were left as they were. Read the output above, do not run again blindly.');
            Log::error('deploy.migrate_failed');

            return self::FAILURE;
        }

        if ($this->option('no-cache')) {
            DeployState::remember($fingerprint);
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
        DeployState::remember($fingerprint);
        Log::info('deploy.post_deploy', ['fingerprint' => substr($fingerprint, 0, 12)]);
        $this->info('post-deploy: done. Check /api/v1/health and open /admin once.');

        return self::SUCCESS;
    }
}
