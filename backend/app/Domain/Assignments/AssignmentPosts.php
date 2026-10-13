<?php

namespace App\Domain\Assignments;

use App\Domain\ClassPosts\AttachmentInput;
use App\Domain\ClassPosts\ClassPosts;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ClassPostFile;
use App\Models\Course;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

/**
 * The post-like parts of an assignment (DESIGN §30.12): its topic (a unit
 * of its course, or free text), its text and its files and links.
 */
final class AssignmentPosts
{
    /**
     * unit_id (a unit of the assignment's course) or a free topic, never both.
     *
     * @return array{0: int|null, 1: string|null}
     */
    public static function topic(?Course $course, mixed $unitId, mixed $topic): array
    {
        if ($unitId !== null && $unitId !== '') {
            if ($course === null || ! Unit::query()->whereKey((int) $unitId)->where('course_id', $course->id)->exists()) {
                throw ValidationException::withMessages(['unit_id' => 'เลือกหน่วยการเรียนรู้ของรายวิชานี้']);
            }

            return [(int) $unitId, null];
        }

        return [null, self::text($topic, true)];
    }

    /** Trimmed text, null when empty; a single-line value also loses its line breaks. */
    public static function text(mixed $value, bool $singleLine = false): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($singleLine) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        }

        return $text === '' ? null : $text;
    }

    /**
     * Replaces the attachment list. Refused once the courseWork exists
     * (Classroom does not patch materials, §30.4) unless the files are the
     * same; files no post or assignment uses afterwards are deleted.
     *
     * @param  list<array{kind: string, file: ClassPostFile|null, url: string|null, title: string}>  $rows
     */
    public static function replaceAttachments(Assignment $assignment, array $rows): void
    {
        $current = $assignment->attachments()->get();
        if ($assignment->googleLink()->exists() && AttachmentInput::signature($current) !== AttachmentInput::rowsSignature($rows)) {
            throw new ApiException('การบ้านนี้โพสต์ลง Google Classroom แล้ว แก้ไฟล์แนบไม่ได้', 'attachments_locked', 409);
        }
        $removed = AttachmentInput::fileIds($current);
        $assignment->attachments()->delete();
        AttachmentInput::write($assignment->attachments(), $rows);
        ClassPosts::deleteUnused($removed);
    }
}
