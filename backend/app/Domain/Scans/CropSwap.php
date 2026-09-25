<?php

namespace App\Domain\Scans;

use RuntimeException;
use Throwable;

/**
 * Crop files written for one scan, kept undoable until the database
 * transaction that points `responses` at them has finished.
 *
 * A rescan reuses the crop path of the existing response
 * (crops/{school}/{assignment}/{response}.webp), so the old image would be
 * overwritten before the new rows are committed. Before a file is replaced
 * or removed it is moved into a backup directory
 * (ScanFiles::replacedDirectory). The caller then settles the swap:
 *
 * - commit(): after the transaction committed, the backups are deleted;
 * - rollback(): when anything failed (a later write, the transaction or the
 *   commit), the new files are deleted and the backups moved back, so the
 *   files match the rows the rollback restored.
 *
 * A backup directory left behind by a crashed process is swept by
 * ScanRetention after a day.
 */
final class CropSwap
{
    /** @var array<string, string> original path => backup path */
    private array $backups = [];

    /** @var list<string> */
    private array $written = [];

    private bool $settled = false;

    public function __construct(private readonly string $backupDirectory) {}

    /** Writes the region's crop to $target, backing up the file already there. */
    public function write(CropSource $source, ScanRegion $region, bool $final, string $target): void
    {
        $this->backUp($target);
        $this->written[] = $target;
        $source->store($region, $final, $target);
    }

    /** Removes a file the new scan no longer has (a final-answer box that is gone); restored on rollback. */
    public function remove(string $path): void
    {
        $this->backUp($path);
    }

    public function commit(): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;

        if ($this->backups !== []) {
            ScanFiles::disk()->deleteDirectory($this->backupDirectory);
        }
    }

    /** Best effort: never throws, so the original error reaches the caller. */
    public function rollback(): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;

        $disk = ScanFiles::disk();
        try {
            if ($this->written !== []) {
                $disk->delete(array_values(array_unique($this->written)));
            }
            foreach ($this->backups as $original => $backup) {
                if ($disk->exists($backup)) {
                    $disk->move($backup, $original);
                }
            }
            if ($this->backups !== []) {
                $disk->deleteDirectory($this->backupDirectory);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function backUp(string $path): void
    {
        if (isset($this->backups[$path])) {
            return;
        }
        $disk = ScanFiles::disk();
        if (! $disk->exists($path)) {
            return;
        }

        $backup = $this->backupDirectory.'/'.basename($path);
        if ($disk->exists($backup)) {
            $disk->delete($backup);
        }
        if (! $disk->move($path, $backup)) {
            throw new RuntimeException("Could not back up {$path}");
        }
        $this->backups[$path] = $backup;
    }
}
