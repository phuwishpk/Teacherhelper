<?php

namespace App\Domain\Courses;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\LessonPlan;
use App\Models\User;

/**
 * The course and lesson plan of an assignment (DESIGN §20.1): every new
 * assignment picks a course bound to its classroom (its subject comes from
 * the course), and may pick one of the course's lesson plans. Older
 * assignments keep course_id NULL; a Classroom website mirror gets the
 * classroom's only course when it has exactly one, otherwise the teacher
 * picks one when approving its key (§19.3, 422 course_required).
 */
final class AssignmentCourses
{
    /**
     * @throws ApiException 422 errors.course_id
     */
    public static function courseFor(User $teacher, int $classroomId, mixed $courseId, string $field = 'course_id'): Course
    {
        $course = filter_var($courseId, FILTER_VALIDATE_INT) === false ? null : Course::query()
            ->where('school_id', $teacher->school_id)
            ->where('created_by', $teacher->id)
            ->whereHas('classrooms', fn ($q) => $q->whereKey($classroomId))
            ->find((int) $courseId);
        if ($course === null) {
            $message = 'รายวิชานี้ไม่ได้ผูกกับห้องเรียนของการบ้าน เลือกรายวิชาของห้องนี้ หรือผูกรายวิชากับห้องก่อน';

            throw new ApiException($message, 'validation_failed', 422, [$field => [$message]]);
        }

        return $course;
    }

    /**
     * @throws ApiException 422 errors.lesson_plan_id
     */
    public static function planFor(Course $course, mixed $planId): ?LessonPlan
    {
        if ($planId === null) {
            return null;
        }
        $plan = filter_var($planId, FILTER_VALIDATE_INT) === false ? null : LessonPlan::query()->where('course_id', $course->id)->find((int) $planId);
        if ($plan === null) {
            throw new ApiException('ไม่พบแผนการสอนนี้ในรายวิชาที่เลือก', 'validation_failed', 422, ['lesson_plan_id' => ['ไม่พบแผนการสอนนี้ในรายวิชาที่เลือก']]);
        }

        return $plan;
    }

    /**
     * Moves an assignment to $course (caller holds the assignment row lock).
     * The subject follows the course; a course of another subject is
     * refused once the assignment has questions (their indicators belong to
     * the subject). A lesson plan of the previous course is dropped.
     *
     * @throws ApiException 422 errors.course_id
     */
    public static function assign(Assignment $assignment, Course $course): void
    {
        if ($assignment->subject_id !== null && $assignment->subject_id !== $course->subject_id && $assignment->questions()->exists()) {
            $message = 'รายวิชาที่เลือกเป็นคนละกลุ่มสาระกับข้อในการบ้านนี้';

            throw new ApiException($message, 'validation_failed', 422, ['course_id' => [$message]]);
        }
        if ($assignment->course_id !== $course->id && $assignment->lesson_plan_id !== null) {
            $assignment->lesson_plan_id = null;
        }
        $assignment->course_id = $course->id;
        $assignment->subject_id = $course->subject_id;
    }

    /** The classroom's course when it is bound to exactly one (for a Classroom website mirror). */
    public static function onlyCourseOf(int $classroomId): ?Course
    {
        $courses = Course::query()->whereHas('classrooms', fn ($q) => $q->whereKey($classroomId))->limit(2)->get();

        return $courses->count() === 1 ? $courses->first() : null;
    }
}
