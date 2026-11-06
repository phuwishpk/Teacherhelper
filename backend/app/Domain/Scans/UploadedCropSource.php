<?php

namespace App\Domain\Scans;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/** Crops taken from the POST /scans multipart fields named in meta.regions. */
final readonly class UploadedCropSource implements CropSource
{
    /**
     * @param  array<string, UploadedFile>  $files  validated multipart files by field name
     */
    public function __construct(private array $files) {}

    public function store(ScanRegion $region, bool $final, string $target): void
    {
        $field = $final ? $region->finalFile : $region->file;
        $file = $field !== null ? ($this->files[$field] ?? null) : null;
        if (! $file instanceof UploadedFile) {
            throw new RuntimeException("Missing upload for {$region->regionId}".($final ? ' (final)' : ''));
        }

        self::put($file, $target);
    }

    public static function put(UploadedFile $file, string $target): void
    {
        $stored = ScanFiles::disk()->putFileAs(dirname($target), $file, basename($target));
        if ($stored === false) {
            throw new RuntimeException("Could not store {$target}");
        }
    }
}
