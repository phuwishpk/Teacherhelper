<?php

namespace App\Domain\Ops;

use Illuminate\Support\Facades\File;

/**
 * What `eduvision:post-deploy --when-changed` needs to know (docs/HOSTING.md
 * §5): has the deployed code changed since the last post-deploy, and does
 * vendor/ still match composer.lock. Plesk Git pulls the code on its own,
 * but cannot run PHP, so a Scheduled Task asks these two questions every
 * few minutes and only migrates and rebuilds the caches when the answer
 * is yes.
 *
 * The fingerprint is a hash over path, size and mtime of every file under
 * the directories that matter plus the whole composer.lock, kept in
 * storage/framework (not the cache: optimize:clear empties the cache).
 */
final class DeployState
{
    /** Directories, relative to base_path(), whose change means "deployed". */
    public const WATCHED = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'composer.lock'];

    public static function fingerprintPath(): string
    {
        return storage_path('framework/deploy.fingerprint');
    }

    /** Hash of the code tree as deployed right now. */
    public static function fingerprint(?string $base = null): string
    {
        $base = $base ?? base_path();
        $hash = hash_init('sha256');
        foreach (self::WATCHED as $entry) {
            $path = $base.DIRECTORY_SEPARATOR.$entry;
            if (is_file($path)) {
                hash_update($hash, $entry.':'.md5_file($path)."\n");

                continue;
            }
            if (! is_dir($path)) {
                continue;
            }
            $files = File::allFiles($path);
            usort($files, fn ($a, $b) => strcmp($a->getPathname(), $b->getPathname()));
            foreach ($files as $file) {
                hash_update($hash, substr($file->getPathname(), strlen($base)).':'.$file->getSize().':'.$file->getMTime()."\n");
            }
        }

        return hash_final($hash);
    }

    public static function lastFingerprint(): ?string
    {
        $path = self::fingerprintPath();

        return is_file($path) ? trim((string) file_get_contents($path)) : null;
    }

    public static function remember(string $fingerprint): void
    {
        File::ensureDirectoryExists(dirname(self::fingerprintPath()));
        file_put_contents(self::fingerprintPath(), $fingerprint."\n");
    }

    public static function forget(): void
    {
        File::delete(self::fingerprintPath());
    }

    /**
     * Packages whose installed version differs from composer.lock, as
     * "name lock→installed" strings; empty when vendor/ is up to date. Plesk's
     * Git deploy cannot run Composer, so a commit that changes composer.lock
     * needs the Composer extension by hand (HOSTING.md §4.3) and this says so.
     *
     * @return list<string>
     */
    public static function vendorMismatches(?string $base = null): array
    {
        $base = $base ?? base_path();
        $lock = json_decode((string) @file_get_contents($base.'/composer.lock'), true);
        $installed = json_decode((string) @file_get_contents($base.'/vendor/composer/installed.json'), true);
        if (! is_array($lock) || ! is_array($installed)) {
            return [];
        }
        $have = [];
        foreach ($installed['packages'] ?? $installed as $package) {
            if (is_array($package) && isset($package['name'], $package['version'])) {
                $have[$package['name']] = $package['version'];
            }
        }
        $out = [];
        foreach ($lock['packages'] ?? [] as $package) {
            $name = $package['name'] ?? null;
            $want = $package['version'] ?? null;
            if ($name === null || $want === null) {
                continue;
            }
            $got = $have[$name] ?? null;
            if ($got !== $want) {
                $out[] = "{$name} {$want}→".($got ?? 'missing');
            }
        }

        return $out;
    }
}
