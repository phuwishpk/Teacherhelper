<?php

namespace App\Domain\Courses;

use Illuminate\Support\Facades\DB;

/**
 * Keeps units.position and lesson_plans.position a gap-free 1..n sequence
 * per course (units have UNIQUE (course_id, position)). Rows are first
 * moved out of the way (+OFFSET) and then written in their final order,
 * which works row by row on both MariaDB and SQLite (as QuestionPositions).
 * Callers run inside a transaction that locked the course row.
 */
final class CoursePositions
{
    public const UNITS = 'units';

    public const LESSON_PLANS = 'lesson_plans';

    private const OFFSET = 10000;

    public static function next(string $table, int $courseId): int
    {
        return (int) DB::table($table)->where('course_id', $courseId)->max('position') + 1;
    }

    /** Puts row $id at $position (clamped to 1..n) and renumbers the rest. */
    public static function move(string $table, int $courseId, int $id, int $position): int
    {
        $ids = array_values(array_filter(self::orderedIds($table, $courseId), fn (int $other) => $other !== $id));
        $index = max(0, min($position - 1, count($ids)));
        array_splice($ids, $index, 0, [$id]);
        self::rewrite($table, $courseId, $ids);

        return $index + 1;
    }

    /** Renumbers 1..n in the current order (after a delete). */
    public static function compact(string $table, int $courseId): void
    {
        self::rewrite($table, $courseId, self::orderedIds($table, $courseId));
    }

    /**
     * @return list<int>
     */
    private static function orderedIds(string $table, int $courseId): array
    {
        return DB::table($table)
            ->where('course_id', $courseId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    private static function rewrite(string $table, int $courseId, array $orderedIds): void
    {
        $current = DB::table($table)->where('course_id', $courseId)->pluck('position', 'id');
        $changed = array_filter($orderedIds, fn (int $id, int $i) => (int) $current[$id] !== $i + 1, ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return;
        }

        DB::table($table)->whereIn('id', $changed)->increment('position', self::OFFSET);
        foreach ($changed as $index => $id) {
            DB::table($table)->where('id', $id)->update(['position' => $index + 1]);
        }
    }
}
