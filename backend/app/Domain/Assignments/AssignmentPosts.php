<?php

namespace App\Domain\Assignments;

use App\Domain\ClassPosts\AttachmentInput;
use App\Domain\ClassPosts\ClassPosts;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ClassPostFile;
use App\Models\Course;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
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
     * The chosen students of homework (DESIGN §30.13): members of the
     * classroom, at least one. Null = the whole classroom.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>|null
     */
    public static function recipients(Assignment $assignment, mixed $audience, mixed $ids): ?array
    {
        if ($audience !== Assignment::AUDIENCE_STUDENTS) {
            return null;
        }
        if ($assignment->isExam()) {
            throw ValidationException::withMessages(['audience' => 'ข้อสอบมอบให้ทั้งห้องเท่านั้น']);
        }
        $ids = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
        if ($ids === []) {
            throw ValidationException::withMessages(['student_ids' => 'เลือกนักเรียนอย่างน้อยหนึ่งคน']);
        }
        $found = DB::table('classroom_students')->where('classroom_id', $assignment->classroom_id)->whereIn('student_id', $ids)->count();
        if ($found !== count($ids)) {
            throw ValidationException::withMessages(['student_ids' => 'เลือกได้เฉพาะนักเรียนของห้องนี้']);
        }
        sort($ids);

        return $ids;
    }

    /**
     * Writes who the work is for. Refused once the courseWork exists:
     * Classroom does not change assignees after the fact (409 audience_locked)
     * unless the list is the same.
     *
     * @param  list<int>|null  $studentIds
     */
    public static function setRecipients(Assignment $assignment, ?array $studentIds): void
    {
        $current = $assignment->isForStudents() ? $assignment->recipients()->orderBy('users.id')->pluck('users.id')->map(fn ($id) => (int) $id)->all() : null;
        if ($current === $studentIds) {
            return;
        }
        if ($assignment->googleLink()->exists()) {
            throw new ApiException('การบ้านนี้โพสต์ลง Google Classroom แล้ว เปลี่ยนผู้รับไม่ได้', 'audience_locked', 409);
        }
        $assignment->forceFill(['audience' => $studentIds === null ? Assignment::AUDIENCE_ALL : Assignment::AUDIENCE_STUDENTS])->save();
        $assignment->recipients()->sync($studentIds ?? []);
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
