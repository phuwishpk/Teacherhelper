<?php

namespace App\Domain\Assignments;

use App\Models\Assignment;
use App\Models\Question;

/**
 * Keeps questions.position a gap-free 1..n sequence per assignment despite the
 * UNIQUE (assignment_id, position) key. Rows are first moved out of the way
 * (+OFFSET) and then written in their final order, which works row-by-row on
 * both MariaDB and SQLite. Callers hold the assignment row lock.
 */
final class QuestionPositions
{
    private const OFFSET = 10000;

    public static function next(Assignment $assignment): int
    {
        return (int) Question::query()->where('assignment_id', $assignment->id)->max('position') + 1;
    }

    /** Moves $question to $position (clamped to 1..n) and renumbers the rest. */
    public static function move(Question $question, int $position): void
    {
        $ids = self::orderedIds($question->assignment_id);
        $ids = array_values(array_filter($ids, fn (int $id) => $id !== $question->id));
        $index = max(0, min($position - 1, count($ids)));
        array_splice($ids, $index, 0, [$question->id]);

        self::rewrite($question->assignment_id, $ids);
        $question->position = $index + 1;
        $question->syncOriginalAttribute('position');
    }

    /** Renumbers 1..n in the current order (after a delete). */
    public static function compact(int $assignmentId): void
    {
        self::rewrite($assignmentId, self::orderedIds($assignmentId));
    }

    /**
     * @return list<int>
     */
    private static function orderedIds(int $assignmentId): array
    {
        return Question::query()
            ->where('assignment_id', $assignmentId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    private static function rewrite(int $assignmentId, array $orderedIds): void
    {
        $current = Question::query()->where('assignment_id', $assignmentId)->pluck('position', 'id');
        $changed = array_filter($orderedIds, fn (int $id, int $i) => (int) $current[$id] !== $i + 1, ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return;
        }

        Question::query()->whereIn('id', $changed)->increment('position', self::OFFSET);
        foreach ($changed as $index => $id) {
            Question::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }
}
