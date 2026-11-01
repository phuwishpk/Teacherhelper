<?php

namespace App\Domain\Firestore;

use App\Jobs\SignalClassFeedJob;
use App\Models\ClassroomTeacher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Live class feed and attendance (DESIGN §38.11, step 4). A document here is
 * only a signal: "this classroom changed, ask the API again". It holds ids
 * and times, never a title, a body, a name, a status or a score, because
 * MariaDB stays the truth and a post may reach only some of the students.
 */
final class LiveClassroom
{
    /** @return list<int> */
    public static function studentIds(int $classroomId): array
    {
        return DB::table('classroom_students')
            ->where('classroom_id', $classroomId)
            ->orderBy('student_id')
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The same teachers ClassroomAccess::classrooms() lets in: homeroom,
     * creators of a bound course, and co-teachers who accepted.
     *
     * @return list<int>
     */
    public static function teacherIds(int $classroomId): array
    {
        $ids = [
            ...DB::table('classrooms')->where('id', $classroomId)->pluck('teacher_id')->all(),
            ...DB::table('course_classroom')
                ->join('courses', 'courses.id', '=', 'course_classroom.course_id')
                ->where('course_classroom.classroom_id', $classroomId)
                ->pluck('courses.created_by')->all(),
            ...ClassroomTeacher::query()
                ->where('classroom_id', $classroomId)
                ->where('status', ClassroomTeacher::STATUS_ACCEPTED)
                ->whereNotNull('teacher_id')
                ->pluck('teacher_id')->all(),
        ];

        return array_values(array_unique(array_map(intval(...), array_filter($ids, fn ($id) => $id !== null))));
    }

    /** @return array<string, mixed> */
    public static function feedFields(int $classroomId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'classroom_id' => $classroomId,
            'updated_at' => $now,
            'expires_at' => $now->addDays(LiveGrading::RETENTION_DAYS),
        ];
    }

    /** @return array<string, mixed> */
    public static function attendanceFields(int $courseId, int $classroomId, ?CarbonImmutable $now = null): array
    {
        return ['course_id' => $courseId] + self::feedFields($classroomId, $now);
    }

    /** A post or a homework of the classroom changed; a no-op while Firestore is off. */
    public static function feedChanged(int $classroomId): void
    {
        if (! FirestoreConfig::enabled()) {
            return;
        }
        SignalClassFeedJob::dispatch($classroomId)->afterCommit();
    }
}
