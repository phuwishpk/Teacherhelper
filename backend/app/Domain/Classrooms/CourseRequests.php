<?php

namespace App\Domain\Classrooms;

use App\Events\CourseRequestCreated;
use App\Events\CourseRequestDecided;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\ClassroomCourseRequest;
use App\Models\Course;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shared homerooms (DESIGN §24.7): a subject teacher asks to bind an own
 * course to another teacher's classroom, the homeroom teacher approves or
 * declines, the requester may cancel while it is pending, and an admin binds
 * directly in Filament. course_classroom stays the source of truth; the
 * requests are the record of who asked and who decided.
 *
 * Pending duplicates of one (classroom, course) are prevented under
 * Cache::lock('course-request:{classroom_id}:{course_id}') (no partial
 * unique index on MariaDB). Every decision locks the request row, so a
 * decided request never changes again (409 request_closed).
 */
final class CourseRequests
{
    /** How long a request waits for the lock of its (classroom, course) pair. */
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * Asks to bind $course (the teacher's own) to $classroom (same school).
     * The homeroom teacher of the classroom binds at once without a request
     * (returns null).
     *
     * @throws ApiException 409 classroom_closed | course_already_in_classroom | request_pending
     */
    public function request(User $teacher, Classroom $classroom, Course $course, ?string $message): ?ClassroomCourseRequest
    {
        ClosedClassrooms::assertOpen($classroom);
        $message = $message === null ? null : (trim($message) === '' ? null : trim($message));

        return self::locked($classroom->id, $course->id, function () use ($teacher, $classroom, $course, $message) {
            return DB::transaction(function () use ($teacher, $classroom, $course, $message) {
                if (self::bound($classroom->id, $course->id)) {
                    throw self::alreadyBound();
                }
                if (ClassroomAccess::homeroomOf($teacher, $classroom)) {
                    $classroom->courses()->syncWithoutDetaching([$course->id]);
                    self::cancelPending($classroom->id, $course->id, $teacher);

                    return null;
                }
                if (self::pendingQuery($classroom->id, $course->id)->exists()) {
                    throw new ApiException('มีคำขอผูกรายวิชานี้กับห้องนี้รอครูประจำชั้นอยู่แล้ว', 'request_pending', 409);
                }

                $request = ClassroomCourseRequest::create([
                    'classroom_id' => $classroom->id,
                    'course_id' => $course->id,
                    'requested_by' => $teacher->id,
                    'origin' => ClassroomCourseRequest::ORIGIN_TEACHER,
                    'status' => ClassroomCourseRequest::STATUS_PENDING,
                    'message' => $message,
                ]);
                CourseRequestCreated::dispatch($request->id);

                return $request;
            });
        });
    }

    /**
     * The homeroom teacher approves: the course is bound to the classroom.
     *
     * @throws ApiException 409 request_closed | classroom_closed
     */
    public function approve(User $homeroom, ClassroomCourseRequest $request): ClassroomCourseRequest
    {
        return self::locked($request->classroom_id, $request->course_id, function () use ($homeroom, $request) {
            return DB::transaction(function () use ($homeroom, $request) {
                $row = $this->lockPending($request);
                $classroom = Classroom::query()->findOrFail($row->classroom_id);
                ClosedClassrooms::assertOpen($classroom);
                $classroom->courses()->syncWithoutDetaching([$row->course_id]);
                $row->forceFill([
                    'status' => ClassroomCourseRequest::STATUS_APPROVED,
                    'decided_by' => $homeroom->id,
                    'decided_at' => now(),
                ])->save();
                CourseRequestDecided::dispatch($row->id);

                return $row;
            });
        });
    }

    /**
     * @throws ApiException 409 request_closed
     */
    public function decline(User $homeroom, ClassroomCourseRequest $request, ?string $reason): ClassroomCourseRequest
    {
        $reason = $reason === null ? null : (trim($reason) === '' ? null : trim($reason));

        return DB::transaction(function () use ($homeroom, $request, $reason) {
            $row = $this->lockPending($request);
            $row->forceFill([
                'status' => ClassroomCourseRequest::STATUS_DECLINED,
                'decided_by' => $homeroom->id,
                'decided_at' => now(),
                'decline_reason' => $reason,
            ])->save();
            CourseRequestDecided::dispatch($row->id);

            return $row;
        });
    }

    /**
     * The requester takes back a pending request.
     *
     * @throws ApiException 409 request_closed
     */
    public function cancel(User $requester, ClassroomCourseRequest $request): ClassroomCourseRequest
    {
        return DB::transaction(function () use ($requester, $request) {
            $row = $this->lockPending($request);
            $row->forceFill([
                'status' => ClassroomCourseRequest::STATUS_CANCELLED,
                'decided_by' => $requester->id,
                'decided_at' => now(),
            ])->save();

            return $row;
        });
    }

    /**
     * An admin binds a course of a teacher of the school directly (Filament
     * "เพิ่มครูประจำวิชา"): course_classroom plus an approved request with
     * origin admin for the record. A pending request of the pair is cancelled.
     *
     * @throws ApiException 409 classroom_closed | course_already_in_classroom, 422 course_not_in_school
     */
    public function assignByAdmin(User $admin, Classroom $classroom, Course $course): ClassroomCourseRequest
    {
        ClosedClassrooms::assertOpen($classroom);
        if ((int) $course->school_id !== (int) $classroom->school_id) {
            throw new ApiException('รายวิชานี้ไม่ได้อยู่ในโรงเรียนของห้อง', 'course_not_in_school', 422, ['course_id' => ['รายวิชานี้ไม่ได้อยู่ในโรงเรียนของห้อง']]);
        }

        return self::locked($classroom->id, $course->id, function () use ($admin, $classroom, $course) {
            return DB::transaction(function () use ($admin, $classroom, $course) {
                if (self::bound($classroom->id, $course->id)) {
                    throw self::alreadyBound();
                }
                $classroom->courses()->syncWithoutDetaching([$course->id]);
                self::cancelPending($classroom->id, $course->id, $admin);

                return ClassroomCourseRequest::create([
                    'classroom_id' => $classroom->id,
                    'course_id' => $course->id,
                    'requested_by' => $admin->id,
                    'origin' => ClassroomCourseRequest::ORIGIN_ADMIN,
                    'status' => ClassroomCourseRequest::STATUS_APPROVED,
                    'decided_by' => $admin->id,
                    'decided_at' => now(),
                ]);
            });
        });
    }

    /**
     * Unbinds the course from the classroom (§24.7 step 5): refused while
     * the classroom has assignments of the course (409 course_in_use, §20.1).
     *
     * @throws ApiException 409 course_in_use
     */
    public function unbind(Classroom $classroom, Course $course): void
    {
        DB::transaction(function () use ($classroom, $course) {
            Course::query()->lockForUpdate()->findOrFail($course->id);
            if (Assignment::query()->where('course_id', $course->id)->where('classroom_id', $classroom->id)->exists()) {
                $message = "ห้อง {$classroom->name} มีการบ้านของรายวิชานี้ เลิกผูกไม่ได้ ห้องที่จบปีให้ใช้ \"ปิดห้อง\" แทน";

                throw new ApiException($message, 'course_in_use', 409, ['course_id' => [$message]]);
            }
            $classroom->courses()->detach($course->id);
        });
    }

    /** Closing a classroom cancels its pending requests (DESIGN §24.6). */
    public static function cancelAllPending(Classroom $classroom, User $actor): int
    {
        return ClassroomCourseRequest::query()
            ->where('classroom_id', $classroom->id)
            ->where('status', ClassroomCourseRequest::STATUS_PENDING)
            ->update([
                'status' => ClassroomCourseRequest::STATUS_CANCELLED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public static function bound(int $classroomId, int $courseId): bool
    {
        return DB::table('course_classroom')->where('classroom_id', $classroomId)->where('course_id', $courseId)->exists();
    }

    /**
     * @throws ApiException 409 request_closed
     */
    private function lockPending(ClassroomCourseRequest $request): ClassroomCourseRequest
    {
        $row = ClassroomCourseRequest::query()->lockForUpdate()->findOrFail($request->id);
        if (! $row->isPending()) {
            throw new ApiException('คำขอนี้ได้รับการตัดสินหรือถูกยกเลิกแล้ว', 'request_closed', 409);
        }

        return $row;
    }

    private static function cancelPending(int $classroomId, int $courseId, User $actor): void
    {
        self::pendingQuery($classroomId, $courseId)->update([
            'status' => ClassroomCourseRequest::STATUS_CANCELLED,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return Builder<ClassroomCourseRequest> */
    private static function pendingQuery(int $classroomId, int $courseId)
    {
        return ClassroomCourseRequest::query()
            ->where('classroom_id', $classroomId)
            ->where('course_id', $courseId)
            ->where('status', ClassroomCourseRequest::STATUS_PENDING);
    }

    private static function alreadyBound(): ApiException
    {
        return new ApiException('รายวิชานี้ผูกกับห้องนี้อยู่แล้ว', 'course_already_in_classroom', 409);
    }

    /**
     * Runs $work under the (classroom, course) lock; another request on the
     * same pair that holds it longer than LOCK_WAIT_SECONDS is a 409 request_busy.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private static function locked(int $classroomId, int $courseId, Closure $work): mixed
    {
        try {
            return Cache::lock("course-request:{$classroomId}:{$courseId}", 10)->block(self::LOCK_WAIT_SECONDS, $work);
        } catch (LockTimeoutException) {
            throw new ApiException('มีการทำรายการกับรายวิชาและห้องนี้อยู่ ลองอีกครั้ง', 'request_busy', 409);
        }
    }
}
