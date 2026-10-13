<?php

namespace Tests\Feature\Console;

use App\Domain\Ops\DeployState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** `eduvision:post-deploy --when-changed`: the hands-off deploy of HOSTING.md §5. */
class PostDeployWhenChangedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DeployState::forget();
    }

    protected function tearDown(): void
    {
        DeployState::forget();
        parent::tearDown();
    }

    public function test_it_acts_once_per_deployed_tree(): void
    {
        $this->artisan('eduvision:post-deploy', ['--when-changed' => true, '--no-cache' => true])
            ->expectsOutputToContain('migrations done')
            ->assertSuccessful();
        $this->assertSame(DeployState::fingerprint(), DeployState::lastFingerprint());

        $this->artisan('eduvision:post-deploy', ['--when-changed' => true, '--no-cache' => true])
            ->expectsOutputToContain('unchanged since the last run')
            ->assertSuccessful();

        // Something deployed: a different tree means the next run works again.
        DeployState::remember('stale');
        $this->artisan('eduvision:post-deploy', ['--when-changed' => true, '--no-cache' => true])
            ->expectsOutputToContain('migrations done')
            ->assertSuccessful();
    }

    public function test_the_fingerprint_follows_the_watched_files(): void
    {
        $base = sys_get_temp_dir().'/deploy-state-'.uniqid();
        File::ensureDirectoryExists($base.'/routes');
        file_put_contents($base.'/routes/api.php', '<?php // a');
        file_put_contents($base.'/composer.lock', '{}');
        $one = DeployState::fingerprint($base);

        $this->assertSame($one, DeployState::fingerprint($base));
        file_put_contents($base.'/composer.lock', '{"changed":1}');
        $this->assertNotSame($one, DeployState::fingerprint($base));

        File::deleteDirectory($base);
    }

    public function test_vendor_mismatches_compare_the_lock_with_what_is_installed(): void
    {
        $base = sys_get_temp_dir().'/deploy-vendor-'.uniqid();
        File::ensureDirectoryExists($base.'/vendor/composer');
        file_put_contents($base.'/composer.lock', json_encode(['packages' => [
            ['name' => 'acme/a', 'version' => 'v1.2.0'],
            ['name' => 'acme/b', 'version' => 'v2.0.0'],
        ]]));
        file_put_contents($base.'/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'acme/a', 'version' => 'v1.2.0'],
            ['name' => 'acme/b', 'version' => 'v1.9.0'],
        ]]));

        $this->assertSame(['acme/b v2.0.0→v1.9.0'], DeployState::vendorMismatches($base));

        File::deleteDirectory($base);
        $this->assertSame([], DeployState::vendorMismatches($base), 'no files = nothing to say');
    }

    public function test_the_real_vendor_matches_the_lock(): void
    {
        $this->assertSame([], DeployState::vendorMismatches());
    }
}
