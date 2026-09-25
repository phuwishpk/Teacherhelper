<?php

namespace App\Domain\Scans;

/**
 * Where the crop images of a scan come from when ResponseWriter stores them:
 * the multipart upload (a new active scan) or the pending stash (a confirmed
 * rescan of a published submission).
 */
interface CropSource
{
    /** Writes the region's crop (or its final-answer crop) to $target on the private disk. */
    public function store(ScanRegion $region, bool $final, string $target): void;
}
