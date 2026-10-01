<?php

namespace App\Domain\Gradebook;

use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;

/**
 * DESIGN §23.11: every teacher route works on a course the teacher created
 * (404 otherwise, via CourseController::ownQuery) and on a classroom bound
 * to that course that the teacher teaches (422 errors.classroom_id).
 */
final class GradebookAccess
{
    /**
     * @throws ApiException 422 errors.<field>
     */
    public static function classroom(User $teacher, Course $course, mixed $classroomId, string $field = 'classroom_id'): Classroom
    {
        $classroom = filter_var($classroomId, FILTER_VALIDATE_INT) === false ? null : Classroom::query()
            ->where('teacher_id', $teacher->id)
            ->where('school_id', $teacher->school_id)
            ->whereHas('courses', fn ($q) => $q->whereKey($course->id))
            ->find((int) $classroomId);
        if ($classroom === null) {
            $message = 'เลือกห้องเรียนของคุณที่ผูกกับรายวิชานี้';

            throw new ApiException($message, 'validation_failed', 422, [$field => [$message]]);
        }

        return $classroom;
    }

    /** 409 gradebook_not_configured until the course has categories (§23.7). */
    public static function assertConfigured(Course $course): void
    {
        if (! GradebookSettings::configured($course)) {
            throw new ApiException('ยังไม่ได้ตั้งค่าสมุดคะแนนของรายวิชานี้', 'gradebook_not_configured', 409);
        }
    }
}
