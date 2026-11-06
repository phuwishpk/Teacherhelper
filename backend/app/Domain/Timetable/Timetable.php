<?php

namespace App\Domain\Timetable;

use App\Domain\Classrooms\ClassroomAccess;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\TimetableCancellation;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The weekly timetable (DESIGN §29.5, §29.12). A teacher sees every slot
 * of their own courses in one week, plus the slots of other teachers'
 * courses in the classrooms they share, as busy blocks; a student sees the
 * slots of their classrooms.
 */
final class Timetable
{
    public const WEEKDAYS = ['จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์', 'อาทิตย์'];

    /**
     * The teacher's own slots.
     *
     * @return Builder<TimetableSlot>
     */
    public static function own(User $teacher): Builder
    {
        return TimetableSlot::query()
            ->whereIn('course_id', Course::query()->select('id')->where('created_by', $teacher->id)->where('school_id', $teacher->school_id))
            ->with(['course:id,code,name', 'classroom:id,name']);
    }

    /**
     * The slots of other teachers' courses in classrooms the teacher is in
     * (homeroom or subject): when the shared room is taken.
     *
     * @return Builder<TimetableSlot>
     */
    public static function busy(User $teacher): Builder
    {
        return TimetableSlot::query()
            ->whereIn('classroom_id', ClassroomAccess::classrooms($teacher)->select('classrooms.id'))
            ->whereNotIn('course_id', Course::query()->select('id')->where('created_by', $teacher->id))
            ->with(['classroom:id,name']);
    }

    /**
     * The slots of the student's classrooms.
     *
     * @return Builder<TimetableSlot>
     */
    public static function forStudent(User $student): Builder
    {
        return TimetableSlot::query()
            ->whereIn('classroom_id', DB::table('classroom_students')->select('classroom_id')->where('student_id', $student->id))
            ->whereIn('classroom_id', Classroom::query()->select('id')->whereNull('closed_at'))
            ->with(['course:id,code,name,created_by', 'course.creator:id,name', 'classroom:id,name']);
    }

    /**
     * The cancellations of the given slots within a week.
     *
     * @param  list<int>  $slotIds
     * @return Collection<int, TimetableCancellation>
     */
    public static function cancellations(array $slotIds, Carbon $weekStart): Collection
    {
        if ($slotIds === []) {
            return collect();
        }

        return TimetableCancellation::query()
            ->whereIn('timetable_slot_id', $slotIds)
            ->whereBetween('held_on', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
            ->orderBy('held_on')
            ->get();
    }

    /** The Monday of the week that holds $date (Asia/Bangkok). */
    public static function weekStart(?string $date): Carbon
    {
        $day = $date === null ? Carbon::now('Asia/Bangkok') : Carbon::parse($date, 'Asia/Bangkok');

        return $day->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    public static function slot(TimetableSlot $slot, bool $own = true): array
    {
        return [
            'id' => $slot->id,
            'course' => $own && $slot->relationLoaded('course') && $slot->course !== null
                ? ['id' => $slot->course->id, 'code' => (string) $slot->course->code, 'name' => (string) $slot->course->name]
                : null,
            'classroom' => $slot->relationLoaded('classroom') && $slot->classroom !== null ? ['id' => $slot->classroom->id, 'name' => (string) $slot->classroom->name] : null,
            'weekday' => $slot->weekday,
            'period_no' => $slot->period_no,
            'starts_at' => self::time($slot->starts_at),
            'ends_at' => self::time($slot->ends_at),
            'own' => $own,
        ];
    }

    /** "08:30" from a TIME column, null when unset. */
    public static function time(?string $value): ?string
    {
        return $value === null || $value === '' ? null : substr($value, 0, 5);
    }
}
