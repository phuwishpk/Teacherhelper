<?php

namespace App\Domain\Gradebook;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\GradebookCategory;

/**
 * The gradebook category of an assignment (DESIGN §23.3): new homework
 * gets the course's homework default unless one is picked; a new exam must
 * pick one once the course's gradebook is set up; the category must belong
 * to the assignment's course (422 errors.gradebook_category_id). Moving to
 * another course resets it to that course's homework default
 * (AssignmentCourses::assign).
 */
final class AssignmentCategories
{
    public const FIELD = 'gradebook_category_id';

    /**
     * @param  bool  $sent  the request carried gradebook_category_id
     */
    public static function forNew(int $courseId, bool $exam, bool $sent, mixed $categoryId): ?int
    {
        if ($sent && $categoryId !== null) {
            return self::ofCourse($courseId, $categoryId);
        }
        if ($exam) {
            if (GradebookCategory::query()->where('course_id', $courseId)->exists()) {
                throw self::invalid('เลือกหมวดคะแนนของข้อสอบ (เช่น กลางภาค หรือ ปลายภาค)');
            }

            return null;
        }

        return $sent ? null : GradebookSettings::homeworkDefaultId($courseId);
    }

    /** Sets a picked category (or none) on an assignment; the caller saves. */
    public static function set(Assignment $assignment, mixed $categoryId): void
    {
        if ($categoryId === null) {
            $assignment->gradebook_category_id = null;

            return;
        }
        if ($assignment->course_id === null) {
            throw self::invalid('เลือกรายวิชาของงานก่อนเลือกหมวดคะแนน');
        }
        $assignment->gradebook_category_id = self::ofCourse($assignment->course_id, $categoryId);
    }

    private static function ofCourse(int $courseId, mixed $categoryId): int
    {
        $ok = filter_var($categoryId, FILTER_VALIDATE_INT) !== false
            && GradebookCategory::query()->where('course_id', $courseId)->whereKey((int) $categoryId)->exists();
        if (! $ok) {
            throw self::invalid('หมวดคะแนนนี้ไม่ใช่ของรายวิชาของงาน');
        }

        return (int) $categoryId;
    }

    private static function invalid(string $message): ApiException
    {
        return new ApiException($message, 'validation_failed', 422, [self::FIELD => [$message]]);
    }
}
