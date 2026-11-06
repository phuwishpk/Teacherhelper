<?php

namespace App\Domain\Attendance;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookEntry;
use App\Models\GradebookItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * DESIGN §29.5: what the attendance records of a course in a classroom add
 * up to.
 *
 * - a student's rate = Σ value of the counted statuses / number of counted
 *   records; มาตรง, มาสาย and ขาด are counted with the course's values
 *   (default 1, 0.5, 0), a leave is not counted at all;
 * - the "การเข้าเรียน" gradebook item (gradebook_items.auto_attendance) gets
 *   rate × max_points for every student with a counted record, and no entry
 *   for the others. Only this class writes its entries.
 */
final class AttendanceBook
{
    public const DEFAULT_SCORES = [AttendanceRecord::PRESENT => 1.0, AttendanceRecord::LATE => 0.5, AttendanceRecord::ABSENT => 0.0];

    /**
     * @return array{present: float, late: float, absent: float}
     */
    public static function scores(Course $course): array
    {
        $stored = is_array($course->attendance_scores) ? $course->attendance_scores : [];
        $scores = [];
        foreach (self::DEFAULT_SCORES as $status => $default) {
            $scores[$status] = isset($stored[$status]) && is_numeric($stored[$status]) ? (float) $stored[$status] : $default;
        }

        return $scores;
    }

    /**
     * Per student of the course's sessions in the classroom:
     * counts by status, and the rate (null without a counted record).
     *
     * @return array<int, array{counts: array<string, int>, counted: int, rate: float|null}>
     */
    public static function totals(Course $course, int $classroomId): array
    {
        $rows = DB::table('attendance_records')
            ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
            ->where('attendance_sessions.course_id', $course->id)
            ->where('attendance_sessions.classroom_id', $classroomId)
            ->groupBy('attendance_records.student_id', 'attendance_records.status')
            ->get(['attendance_records.student_id', 'attendance_records.status', DB::raw('count(*) as n')]);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->student_id][(string) $row->status] = (int) $row->n;
        }

        return array_map(fn (array $byStatus) => self::total($byStatus, self::scores($course)), $counts);
    }

    /**
     * @param  array<string, int>  $byStatus
     * @param  array{present: float, late: float, absent: float}  $scores
     * @return array{counts: array<string, int>, counted: int, rate: float|null}
     */
    public static function total(array $byStatus, array $scores): array
    {
        $counts = [];
        foreach (AttendanceRecord::STATUSES as $status) {
            $counts[$status] = $byStatus[$status] ?? 0;
        }
        $counted = 0;
        $earned = 0.0;
        foreach (AttendanceRecord::COUNTED as $status) {
            $counted += $counts[$status];
            $earned += $counts[$status] * $scores[$status];
        }

        return ['counts' => $counts, 'counted' => $counted, 'rate' => $counted === 0 ? null : round($earned / $counted, 4)];
    }

    /** The "การเข้าเรียน" item of the course in the classroom, when the teacher added one. */
    public static function autoItem(int $courseId, int $classroomId): ?GradebookItem
    {
        return GradebookItem::query()
            ->where('course_id', $courseId)
            ->where('classroom_id', $classroomId)
            ->where('auto_attendance', true)
            ->orderBy('id')
            ->first();
    }

    /** Rewrites the entries of the "การเข้าเรียน" item from the records (no item: nothing to do). */
    public static function syncGrades(Course $course, int $classroomId, User $actor): void
    {
        $item = self::autoItem($course->id, $classroomId);
        if ($item === null) {
            return;
        }
        DB::transaction(function () use ($course, $classroomId, $actor, $item) {
            $totals = self::totals($course, $classroomId);
            $entries = GradebookEntry::query()->where('gradebook_item_id', $item->id)->lockForUpdate()->get()->keyBy('student_id');
            foreach ($totals as $studentId => $total) {
                if ($total['rate'] === null) {
                    continue;
                }
                $score = round($total['rate'] * $item->max_points, 2);
                $entry = $entries->pull($studentId) ?? new GradebookEntry(['gradebook_item_id' => $item->id, 'classroom_id' => $classroomId, 'student_id' => $studentId]);
                if (! $entry->exists || (float) $entry->score !== $score || $entry->excused) {
                    $entry->fill(['score' => $score, 'excused' => false, 'updated_by' => $actor->id])->save();
                }
            }
            // No counted record any more (session deleted, or only leaves): the cell is empty again.
            foreach ($entries as $entry) {
                $entry->delete();
            }
        });
    }

    /** Every open classroom of the course with an automatic item (after the course's values changed); a closed classroom keeps its scores (§24.6). */
    public static function syncCourse(Course $course, User $actor): void
    {
        $classroomIds = GradebookItem::query()->where('course_id', $course->id)->where('auto_attendance', true)
            ->whereIn('classroom_id', Classroom::query()->whereNull('closed_at')->select('id'))
            ->distinct()->pluck('classroom_id');
        foreach ($classroomIds as $classroomId) {
            self::syncGrades($course, (int) $classroomId, $actor);
        }
    }

    /**
     * @return array<int, array<string, int>> session id => counts by status
     */
    public static function sessionCounts(Course $course, Classroom $classroom): array
    {
        $rows = DB::table('attendance_records')
            ->whereIn('attendance_session_id', AttendanceSession::query()->where('course_id', $course->id)->where('classroom_id', $classroom->id)->select('id'))
            ->groupBy('attendance_session_id', 'status')
            ->get(['attendance_session_id', 'status', DB::raw('count(*) as n')]);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->attendance_session_id][(string) $row->status] = (int) $row->n;
        }

        return array_map(function (array $byStatus) {
            $counts = [];
            foreach (AttendanceRecord::STATUSES as $status) {
                $counts[$status] = $byStatus[$status] ?? 0;
            }

            return $counts;
        }, $out);
    }
}
