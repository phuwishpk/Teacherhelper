<?php

namespace App\Domain\Scans;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Private-disk layout of scan images (DESIGN §7.3). Nothing here has a public
 * URL: ScanController / ResponseController stream the files after the policy
 * check.
 *
 *   scans/{school}/{assignment}/{scan}.webp                 warped page, deleted after publish
 *   crops/{school}/{assignment}/{response}.webp             answer crop
 *   crops/{school}/{assignment}/{response}_final.webp       final-answer box of show_work
 *   scans/{school}/{assignment}/pending/{scan}/             crops + regions.json of a
 *                                                          pending_confirm rescan until the
 *                                                          teacher confirms it
 */
final class ScanFiles
{
    public const DISK = 'local';

    public static function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(self::DISK);

        return $disk;
    }

    public static function pagePath(int $schoolId, int $assignmentId, int $scanId): string
    {
        return "scans/{$schoolId}/{$assignmentId}/{$scanId}.webp";
    }

    public static function cropPath(int $schoolId, int $assignmentId, int $responseId, bool $final = false): string
    {
        return "crops/{$schoolId}/{$assignmentId}/{$responseId}".($final ? '_final' : '').'.webp';
    }

    public static function pendingDirectory(int $schoolId, int $assignmentId, int $scanId): string
    {
        return "scans/{$schoolId}/{$assignmentId}/pending/{$scanId}";
    }

    /** region ids are checked against the layout (q{question_id}), so they are safe file names. */
    public static function pendingCrop(string $directory, string $regionId, bool $final = false): string
    {
        return $directory.'/'.$regionId.($final ? '_final' : '').'.webp';
    }

    public static function pendingMeta(string $directory): string
    {
        return $directory.'/regions.json';
    }
}
