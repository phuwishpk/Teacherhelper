<?php

namespace App\Domain\Exams;

use App\Domain\Assignments\QuestionPositions;
use App\Models\ExamSection;
use App\Models\Question;

/**
 * Keeps an exam's numbering in order (DESIGN §22.2): sections are 1..m by
 * exam_sections.position and question numbers (questions.position) run on
 * across the whole exam in section order. Both use the move-out-of-the-way
 * trick of QuestionPositions because of the UNIQUE keys. Callers hold the
 * assignment row lock.
 */
final class ExamPositions
{
    private const SECTION_OFFSET = 100;

    /**
     * Renumbers every question 1..n: sections in order, and within a section
     * the current order, or the given order of question ids for that section.
     *
     * @param  array<int, list<int>>  $sectionOrders  section_id => question ids in their new order
     */
    public static function renumber(int $assignmentId, array $sectionOrders = []): void
    {
        $sectionIds = ExamSection::query()->where('assignment_id', $assignmentId)->orderBy('position')->pluck('id');
        $bySection = Question::query()
            ->where('assignment_id', $assignmentId)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'section_id'])
            ->groupBy('section_id');

        $ordered = [];
        foreach ($sectionIds as $sectionId) {
            $ids = $sectionOrders[(int) $sectionId]
                ?? ($bySection->get($sectionId)?->pluck('id')->map(fn ($id) => (int) $id)->all() ?? []);
            array_push($ordered, ...$ids);
        }
        QuestionPositions::rewrite($assignmentId, $ordered);
    }

    /**
     * Writes section positions 1..m in the given order of section ids.
     *
     * @param  list<int>  $orderedSectionIds
     */
    public static function rewriteSections(int $assignmentId, array $orderedSectionIds): void
    {
        $current = ExamSection::query()->where('assignment_id', $assignmentId)->pluck('position', 'id');
        $changed = array_filter($orderedSectionIds, fn (int $id, int $i) => (int) $current[$id] !== $i + 1, ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return;
        }
        ExamSection::query()->whereIn('id', $changed)->increment('position', self::SECTION_OFFSET);
        foreach ($changed as $index => $id) {
            ExamSection::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }

    /**
     * Section ids in order.
     *
     * @return list<int>
     */
    public static function sectionIds(int $assignmentId): array
    {
        return ExamSection::query()->where('assignment_id', $assignmentId)->orderBy('position')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
