<?php

namespace App\Domain\Google;

use App\Models\ClassPostAttachment;
use App\Models\ClassPostFile;
use Illuminate\Support\Facades\Storage;

/**
 * The Classroom `materials` of a post or an assignment (DESIGN §30.2,
 * §30.12): links as they are, uploaded files from the author's Drive,
 * copied there on the first send and reused by every later one.
 */
final class ClassroomMaterials
{
    /**
     * Null when a file's bytes are gone from the server.
     *
     * @param  iterable<object{kind: string, url: string|null, file: ClassPostFile|null}>  $attachments
     * @return list<array<string, mixed>>|null
     *
     * @throws GoogleApiException
     */
    public static function of(GoogleApi $api, iterable $attachments): ?array
    {
        $materials = [];
        foreach ($attachments as $attachment) {
            if ($attachment->kind === ClassPostAttachment::KIND_LINK) {
                $materials[] = ['link' => ['url' => (string) $attachment->url]];

                continue;
            }
            $file = $attachment->file;
            if ($file === null) {
                continue;
            }
            if ($file->drive_file_id === null) {
                $bytes = Storage::disk('local')->get($file->path);
                if ($bytes === null) {
                    return null;
                }
                $file->drive_file_id = $api->uploadDriveFile($file->original_name, $file->mime_type, $bytes);
                $file->save();
            }
            $materials[] = ['driveFile' => ['driveFile' => ['id' => $file->drive_file_id], 'shareMode' => 'VIEW']];
        }

        return $materials;
    }
}
