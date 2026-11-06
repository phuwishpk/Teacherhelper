<?php

namespace App\Domain\Scans;

use RuntimeException;

/**
 * Crops of a pending_confirm scan, stashed on the private disk until the
 * teacher confirms the rescan. They are copied (not moved) so a rolled-back
 * confirmation can simply be retried; the stash is deleted after commit.
 */
final readonly class PendingCropSource implements CropSource
{
    public function __construct(private string $directory) {}

    public function store(ScanRegion $region, bool $final, string $target): void
    {
        $disk = ScanFiles::disk();
        $source = ScanFiles::pendingCrop($this->directory, $region->regionId, $final);
        if (! $disk->exists($source)) {
            throw new RuntimeException("Pending crop {$source} is missing");
        }
        if ($disk->exists($target)) {
            $disk->delete($target);
        }
        if (! $disk->copy($source, $target)) {
            throw new RuntimeException("Could not copy {$source} to {$target}");
        }
    }
}
