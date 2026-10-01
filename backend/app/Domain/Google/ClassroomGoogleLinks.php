<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Models\AssignmentGoogleLink;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Linking a teacher's own Google Classroom course to a classroom (DESIGN
 * §18.6, §24.10): one course per teacher per classroom, and a course in one
 * classroom at most. Used by POST /classrooms/{id}/google-link, by
 * "ผูกกับห้องที่มีอยู่" (link-existing) and by approving a classroom_import
 * course request. Callers hold ClassroomImporter::underCourseLock of the
 * Google course.
 */
final class ClassroomGoogleLinks
{
    /**
     * Links $course to the classroom as $owner's course, replacing the
     * owner's previous course of the classroom.
     *
     * @param  array{course_id: string, name: string}  $course
     *
     * @throws ApiException 409 course_already_linked (another classroom or
     *                      teacher has it), classroom_has_google_posts (the
     *                      owner's work is posted to the previous course)
     */
    public static function put(Classroom $classroom, User $owner, array $course, ?int $appCourseId): ClassroomGoogleLink
    {
        return DB::transaction(function () use ($classroom, $owner, $course, $appCourseId) {
            $current = ClassroomGoogleLink::query()
                ->where('classroom_id', $classroom->id)
                ->where('owner_user_id', $owner->id)
                ->lockForUpdate()
                ->first();
            if ($current !== null && $current->course_id !== $course['course_id'] && self::hasPosts($classroom, $owner)) {
                throw new ApiException(
                    'ห้องเรียนนี้มีการบ้านที่โพสต์ลงคอร์สเดิมแล้ว เปลี่ยนไปผูกคอร์สอื่นไม่ได้',
                    'classroom_has_google_posts',
                    409,
                );
            }
            $taken = ClassroomGoogleLink::query()
                ->where('course_id', $course['course_id'])
                ->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))
                ->exists();
            if ($taken) {
                throw self::alreadyLinked();
            }

            return ClassroomGoogleLink::query()->updateOrCreate(
                ['classroom_id' => $classroom->id, 'owner_user_id' => $owner->id],
                [
                    'course_id' => $course['course_id'],
                    'course_name' => mb_substr($course['name'] !== '' ? $course['name'] : $course['course_id'], 0, 255),
                    'app_course_id' => $appCourseId,
                    'linked_at' => now(),
                ],
            );
        });
    }

    /**
     * The app course of the owner's link: $requested must be the owner's
     * course bound to the classroom; without one, the owner's only bound
     * course. A subject teacher with several must choose (422).
     *
     * @throws ApiException 422 validation_failed (errors.app_course_id)
     */
    public static function appCourseFor(Classroom $classroom, User $owner, ?int $requested): ?int
    {
        $own = Course::query()
            ->where('created_by', $owner->id)
            ->where('school_id', $classroom->school_id)
            ->whereHas('classrooms', fn ($q) => $q->whereKey($classroom->id))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($requested !== null) {
            if (! in_array($requested, $own, true)) {
                throw self::invalidAppCourse('เลือกรายวิชาของคุณที่ผูกกับห้องนี้');
            }

            return $requested;
        }
        if (count($own) === 1) {
            return $own[0];
        }
        if ($own !== [] && (int) $classroom->teacher_id !== (int) $owner->id) {
            throw self::invalidAppCourse('เลือกรายวิชาที่คอร์สนี้ใช้ (app_course_id)');
        }

        return null;
    }

    public static function alreadyLinked(): ApiException
    {
        return new ApiException('คอร์สนี้ผูกกับห้องเรียนอื่นในระบบแล้ว', 'course_already_linked', 409);
    }

    public static function invalidAppCourse(string $message): ApiException
    {
        return new ApiException($message, 'validation_failed', 422, ['app_course_id' => [$message]]);
    }

    /** The owner has work of this classroom posted to Classroom (moving their course would strand it). */
    private static function hasPosts(Classroom $classroom, User $owner): bool
    {
        return AssignmentGoogleLink::query()
            ->where('posted_by', $owner->id)
            ->whereIn('assignment_id', $classroom->assignments()->select('id'))
            ->exists();
    }
}
