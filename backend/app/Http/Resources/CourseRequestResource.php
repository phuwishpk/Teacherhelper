<?php

namespace App\Http\Resources;

use App\Models\ClassroomCourseRequest;

/**
 * A course request of shared homerooms (DESIGN §24.7, §24.12 B):
 * {id, classroom: {id, name, grade_level, academic_year, closed,
 *  homeroom_teacher: {id, name}}, course: {id, code, name, grade_level,
 *  semester, academic_year}, requester: {id, name}, origin, status, message,
 *  google_course_name, decided_at, decline_reason, created_at}.
 * Timestamps are UTC ISO 8601.
 */
final class CourseRequestResource
{
    /** The relations payload() reads. */
    public const RELATIONS = ['classroom.teacher:id,name', 'course', 'requester:id,name'];

    /**
     * @return array<string, mixed>
     */
    public static function payload(ClassroomCourseRequest $request): array
    {
        $request->loadMissing(self::RELATIONS);
        $classroom = $request->classroom;
        $course = $request->course;

        return [
            'id' => $request->id,
            'classroom' => $classroom === null ? null : [
                'id' => $classroom->id,
                'name' => $classroom->name,
                'grade_level' => $classroom->grade_level,
                'academic_year' => $classroom->academic_year,
                'closed' => $classroom->isClosed(),
                'homeroom_teacher' => $classroom->teacher === null ? null : [
                    'id' => $classroom->teacher->id,
                    'name' => $classroom->teacher->name,
                ],
            ],
            'course' => $course === null ? null : [
                'id' => $course->id,
                'code' => $course->code,
                'name' => $course->name,
                'grade_level' => $course->grade_level,
                'semester' => $course->semester,
                'academic_year' => $course->academic_year,
            ],
            'requester' => $request->requester === null ? null : [
                'id' => $request->requester->id,
                'name' => $request->requester->name,
            ],
            'origin' => $request->origin,
            'status' => $request->status,
            'message' => $request->message,
            'google_course_name' => $request->google_course_name,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decline_reason' => $request->decline_reason,
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }
}
