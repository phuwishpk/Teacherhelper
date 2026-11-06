<?php

namespace App\Domain\Pages;

use App\Domain\Scans\ScanFiles;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Files of whole-page submissions on the private disk (DESIGN §19.4):
 *
 *   pages/{school}/{assignment}/{page}.{ext}
 *
 * stored as they came (no conversion on the server, §3.3). They are the only
 * evidence of this path (there are no crops), so they follow the crop
 * retention (schools.crop_retention_until); a superseded page goes in the
 * next eduvision:purge-images run (ScanRetention). Streamed only after the
 * policy check (GET /submission-pages/{id}/image).
 *
 * Accepted types: what Gemini reads directly.
 */
final class PageFiles
{
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'application/pdf' => 'pdf',
    ];

    /** File-name extensions of the accepted types, for a missing or generic MIME type. */
    public const EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'pdf' => 'application/pdf',
    ];

    /** Office and Google files people try to hand in; refused with "save as PDF". */
    private const OFFICE_EXTENSIONS = ['doc', 'docx', 'odt', 'rtf', 'pages', 'gdoc', 'ppt', 'pptx', 'xls', 'xlsx'];

    public static function disk(): FilesystemAdapter
    {
        return ScanFiles::disk();
    }

    public static function path(int $schoolId, int $assignmentId, int $pageId, string $mimeType): string
    {
        return "pages/{$schoolId}/{$assignmentId}/{$pageId}.".(self::TYPES[$mimeType] ?? 'bin');
    }

    /**
     * The accepted MIME type of a file, from its declared type or, when that
     * is missing or generic, its name; null when it is not one Gemini reads.
     */
    public static function acceptedType(?string $mimeType, string $name): ?string
    {
        $mimeType = strtolower(trim((string) $mimeType));
        if ($mimeType === 'image/jpg') {
            $mimeType = 'image/jpeg';
        }
        if (isset(self::TYPES[$mimeType])) {
            return $mimeType;
        }
        if ($mimeType === '' || $mimeType === 'application/octet-stream') {
            return self::EXTENSIONS[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
        }

        return null;
    }

    /** A Word, Office or Google Docs file (by name or declared type): read only once saved as PDF. */
    public static function isOfficeDocument(?string $mimeType, string $name): bool
    {
        $mimeType = strtolower((string) $mimeType);

        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::OFFICE_EXTENSIONS, true)
            || str_contains($mimeType, 'officedocument')
            || str_contains($mimeType, 'msword')
            || str_starts_with($mimeType, 'application/vnd.google-apps.');
    }

    public static function maxBytes(): int
    {
        return (int) config('eduvision.submissions.max_file_mb') * 1024 * 1024;
    }

    public static function maxPages(): int
    {
        return (int) config('eduvision.submissions.max_pages');
    }
}
