<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Production runs `artisan optimize` (config:cache), after which env()
 * returns null everywhere but inside config/*.php. This keeps every env()
 * call inside config/ and every Krucheck variable documented in
 * .env.example, so a deploy never silently loses a setting.
 */
class ConfigCacheSafetyTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function test_env_is_never_read_outside_the_config_directory(): void
    {
        $offenders = [];
        foreach (['app', 'routes', 'bootstrap', 'database', 'resources'] as $dir) {
            foreach (self::phpFiles(self::ROOT.'/'.$dir) as $file) {
                $lines = file($file) ?: [];
                foreach ($lines as $n => $line) {
                    // env('X') / env("X") as a function call; not ->env(), Env::, $env, environment().
                    if (preg_match('/(?<![\w$>:\\\\])env\s*\(/', $line) && ! str_contains($line, '//') && ! str_starts_with(trim($line), '*')) {
                        $offenders[] = substr($file, strlen(self::ROOT) + 1).':'.($n + 1).' '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "env() outside config/ breaks after config:cache:\n".implode("\n", $offenders));
    }

    public function test_every_eduvision_variable_is_documented_in_env_example(): void
    {
        $example = (string) file_get_contents(self::ROOT.'/.env.example');
        $missing = [];
        foreach (['config/eduvision.php', 'config/services.php'] as $file) {
            preg_match_all('/env\(\s*[\'"]([A-Z0-9_]+)[\'"]/', (string) file_get_contents(self::ROOT.'/'.$file), $m);
            foreach ($m[1] as $name) {
                if (! preg_match('/^(GEMINI|FIREBASE|GOOGLE|SEED|HEARTBEAT|ADMIN|QR|WORKSHEET|SCAN)_/', $name)) {
                    continue; // skeleton services (AWS, Postmark, Slack...) are not ours
                }
                if (! preg_match('/^#?\s*'.preg_quote($name, '/').'=/m', $example)) {
                    $missing[] = $name;
                }
            }
        }

        $this->assertSame([], $missing, '.env.example must list: '.implode(', ', $missing));
    }

    public function test_env_example_holds_no_real_secret(): void
    {
        $example = (string) file_get_contents(self::ROOT.'/.env.example');

        $this->assertDoesNotMatchRegularExpression('/AIza[0-9A-Za-z_\-]{35}/', $example, 'a Google API key');
        $this->assertDoesNotMatchRegularExpression('/GOCSPX-[0-9A-Za-z_\-]{10,}/', $example, 'a Google OAuth client secret');
        $this->assertDoesNotMatchRegularExpression('/^APP_KEY=base64:.+/m', $example, 'an application key');
        foreach (['GEMINI_API_KEY', 'GOOGLE_OAUTH_CLIENT_SECRET', 'QR_SIGNING_KEY', 'FIREBASE_CREDENTIALS', 'ADMIN_PASSWORD'] as $name) {
            $this->assertMatchesRegularExpression('/^'.$name.'=\s*$/m', $example, "{$name} is empty in the example");
        }
    }

    public function test_the_test_suite_never_points_at_the_real_gemini(): void
    {
        $phpunit = (string) file_get_contents(self::ROOT.'/phpunit.xml');

        $this->assertMatchesRegularExpression('/name="GEMINI_FAKE" value="true" force="true"/', $phpunit);
        $this->assertMatchesRegularExpression('/name="APP_KEY" value="base64:/', $phpunit, 'the suite must not depend on a local .env');
        $this->assertStringContainsString('name="DB_DATABASE" value=":memory:"', $phpunit);
    }

    public function test_the_suite_never_reads_a_cached_config_or_route_file(): void
    {
        // `artisan optimize` (the smoke procedure and production) writes
        // bootstrap/cache/config.php; with it the suite would run on the .env
        // values (real Gemini key, the developer's database) instead of the
        // ones above. phpunit.xml moves the cache paths somewhere that never exists.
        $phpunit = (string) file_get_contents(self::ROOT.'/phpunit.xml');
        foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $name) {
            $this->assertMatchesRegularExpression('/name="'.$name.'" value="storage\/framework\/testing\/[a-z-]+\.php" force="true"/', $phpunit, $name);
            preg_match('/name="'.$name.'" value="([^"]+)"/', $phpunit, $m);
            $this->assertFileDoesNotExist(self::ROOT.'/'.$m[1]);
        }
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
