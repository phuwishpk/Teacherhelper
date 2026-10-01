<?php

namespace App\Domain\Gradebook;

use App\Domain\Classrooms\ClosedClassrooms;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;

/**
 * DESIGN §23.11, §24.8: every teacher route works on a course the teacher
 * created (404 otherwise, via CourseController::ownQuery) and on a classroom
 * bound to that course (422 errors.classroom_id), whether the teacher is its
 * homeroom or a subject teacher. The homeroom teacher also reads (grid, CSV)
 * the courses of other teachers bound to their classroom: GradebookController::visibleCourse.
 */
final class GradebookAccess
{
    /**
     * @throws ApiException 422 errors.<field>
     */
    public static function classroom(User $teacher, Course $course, mixed $classroomId, string $field = 'classroom_id'): Classroom
    {
        // A classroom an own course is bound to is one the teacher teaches (homeroom or subject, §24.8).
        $classroom = filter_var($classroomId, FILTER_VALIDATE_INT) === false ? null : Classroom::query()
            ->where('school_id', $teacher->school_id)
            ->whereHas('courses', fn ($q) => $q->whereKey($course->id))
            ->find((int) $classroomId);
        if ($classroom === null) {
            $message = 'เลือกห้องเรียนของคุณที่ผูกกับรายวิชานี้';

            throw new ApiException($message, 'validation_failed', 422, [$field => [$message]]);
        }

        return $classroom;
    }

    /**
     * classroom() for a write: a closed classroom (§24.6) is a 409.
     *
     * @throws ApiException 422 errors.<field>, 409 classroom_closed
     */
    public static function openClassroom(User $teacher, Course $course, mixed $classroomId, string $field = 'classroom_id'): Classroom
    {
        $classroom = self::classroom($teacher, $course, $classroomId, $field);
        ClosedClassrooms::assertOpen($classroom);

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
