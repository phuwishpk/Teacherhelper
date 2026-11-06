<?php

namespace App\Domain\ClassPosts;

use Illuminate\Support\Str;

/**
 * Which files a class post may carry (DESIGN §30.2): PDF, pictures and
 * Office files, decided from the bytes the detector reads, not from the
 * name. Shared by the teacher's upload and the files pulled in from
 * Classroom (DESIGN §45.2).
 */
final class ClassPostFileTypes
{
    /** Detected MIME type => the extension the file is stored with. */
    private const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heic',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/msword' => 'doc',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.ms-powerpoint' => 'ppt',
    ];

    /** Containers the detector reports for Office files: the name's extension then decides. */
    private const CONTAINERS = [
        'application/zip' => ['docx', 'xlsx', 'pptx'],
        'application/octet-stream' => ['docx', 'xlsx', 'pptx'],
        'application/vnd.ms-office' => ['doc', 'xls', 'ppt'],
        'application/x-ole-storage' => ['doc', 'xls', 'ppt'],
        'application/cdfv2' => ['doc', 'xls', 'ppt'],
    ];

    /**
     * The type to store and its extension, or null when the file is not
     * one a post may carry.
     *
     * @param  string  $detected  the MIME type finfo read from the bytes
     * @param  string  $name  the file's name (its extension settles Office containers)
     * @return array{0: string, 1: string}|null
     */
    public static function of(string $detected, string $name): ?array
    {
        $mime = strtolower($detected);
        if (isset(self::TYPES[$mime])) {
            return [$mime, self::TYPES[$mime]];
        }
        $named = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($named, self::CONTAINERS[$mime] ?? [], true)) {
            return [(string) array_search($named, self::TYPES, true), $named];
        }

        return null;
    }

    /**
     * Whether a type Drive reports may be one of ours (checked again on
     * the bytes after the download).
     */
    public static function mayBe(string $driveMime): bool
    {
        return isset(self::TYPES[strtolower($driveMime)]);
    }

    /** The given name, without a path, ending with the stored extension. */
    public static function name(string $original, string $extension): string
    {
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', pathinfo($original, PATHINFO_FILENAME)));
        $base = $base === '' ? 'file' : Str::limit($base, 200, '');

        return $base.'.'.$extension;
    }
}
