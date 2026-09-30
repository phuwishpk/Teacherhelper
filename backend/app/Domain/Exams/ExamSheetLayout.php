<?php

namespace App\Domain\Exams;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\Question;

/**
 * Places an exam's answers on the answer-sheet grid (DESIGN §22.7).
 *
 * - Bubble rows (mcq, true_false) run in number order down column 1, then
 *   column 2 … (25 rows per column, fewer when the page has digit bands);
 *   page 2 continues the numbers of page 1.
 * - Numeric questions are digit blocks, four per band, in the last 11 or 22
 *   rows of the grid; a page takes as many bands as the numeric questions
 *   still need (at most 2) and fills the rest with rows (ExamSheetCapacity).
 * - Page 1 of an exam with more than one version carries the version
 *   bubbles; one version has none (it is version ก).
 *
 * The sheet numbers are the numbers of the original order (version ก); every
 * version has the same shape at each number, so one layout serves all
 * versions (§22.8).
 */
final class ExamSheetLayout
{
    /**
     * @throws ApiException 422 exam_sheet_overflow
     */
    public static function forExam(Assignment $exam): ExamSheetPlan
    {
        $plan = self::plan(self::items($exam), max(1, (int) $exam->version_count));
        self::assertFits($plan);

        return $plan;
    }

    /**
     * The exam's answers in number order.
     *
     * @return list<ExamSheetItem>
     */
    public static function items(Assignment $exam): array
    {
        $sections = ExamSection::query()->where('assignment_id', $exam->id)->get()->keyBy('id');
        $items = [];
        foreach (Question::query()->where('assignment_id', $exam->id)->orderBy('position')->get(['id', 'section_id', 'position']) as $question) {
            $section = $sections->get($question->section_id);
            if ($section instanceof ExamSection) {
                $items[] = ExamSheetItem::forSection($section, (int) $question->position);
            }
        }

        return $items;
    }

    /** @throws ApiException 422 exam_sheet_overflow */
    public static function assertFits(ExamSheetPlan $plan): void
    {
        if ($plan->overflows()) {
            throw new ApiException(
                'กระดาษคำตอบยาวเกิน 2 หน้า ลดจำนวนข้อหรือข้อเติมตัวเลข',
                'exam_sheet_overflow',
                422,
            );
        }
    }

    /**
     * @param  list<ExamSheetItem>  $items  in number order
     */
    public static function plan(array $items, int $versionCount): ExamSheetPlan
    {
        $rowItems = array_values(array_filter($items, fn (ExamSheetItem $i) => ! $i->isNumeric()));
        $blockItems = array_values(array_filter($items, fn (ExamSheetItem $i) => $i->isNumeric()));

        $pages = [];
        foreach (ExamSheetCapacity::split(count($rowItems), count($blockItems)) as $index => $alloc) {
            $perColumn = ExamSheetCapacity::rowsPerColumn($alloc['bands']);
            $rows = [];
            foreach (array_splice($rowItems, 0, $alloc['rows']) as $i => $item) {
                $rows[] = self::row($item, intdiv($i, $perColumn) + 1, $i % $perColumn + 1);
            }
            $blocks = [];
            foreach (array_splice($blockItems, 0, $alloc['blocks']) as $i => $item) {
                $band = intdiv($i, ExamSheetCapacity::COLUMNS);
                $blocks[] = self::block($item, $i % ExamSheetCapacity::COLUMNS + 1, $band, ExamSheetGeometry::bandTop($alloc['bands'], $band));
            }

            $pages[] = [
                'page' => $index + 1,
                'bands' => $alloc['bands'],
                'version' => $index === 0 && $versionCount > 1 ? self::versionBubbles($versionCount) : [],
                'rows' => $rows,
                'blocks' => $blocks,
            ];
        }

        return new ExamSheetPlan($pages, $versionCount);
    }

    /**
     * @return list<array{value: int, label: string, cx: float, cy: float, r: float}>
     */
    private static function versionBubbles(int $versionCount): array
    {
        $out = [];
        for ($v = 1; $v <= $versionCount; $v++) {
            $out[] = [
                'value' => $v,
                'label' => ExamVersions::label($v),
                'cx' => ExamSheetGeometry::versionX($v),
                'cy' => ExamSheetGeometry::VERSION_Y,
                'r' => ExamSheetGeometry::VERSION_R,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(ExamSheetItem $item, int $column, int $row): array
    {
        $y = ExamSheetGeometry::rowY($row);
        $bubbles = [];
        foreach ($item->labels() as $i => $label) {
            $bubbles[] = [
                'value' => $i + 1,
                'label' => $label,
                'cx' => ExamSheetGeometry::bubbleX($column, $i + 1),
                'cy' => $y,
                'r' => ExamSheetGeometry::BUBBLE_R,
            ];
        }

        return [
            'sheet_no' => $item->sheetNo,
            'column' => $column,
            'row' => $row,
            'rect' => [
                'x' => ExamSheetGeometry::columnLeft($column),
                'y' => ExamSheetGeometry::rowTop($row),
                'w' => ExamSheetGeometry::COLUMN_W,
                'h' => ExamSheetGeometry::ROW_STEP,
            ],
            'bubbles' => $bubbles,
        ];
    }

    /**
     * A digit block: [sign column] + digit columns (+ one for the point).
     * Row 1 holds "−" (sign column) and "." (every digit column when
     * decimals are allowed); rows 2–11 are 0–9 in every digit column.
     *
     * @return array<string, mixed>
     */
    private static function block(ExamSheetItem $item, int $column, int $band, float $top): array
    {
        $col = 1;
        $sign = null;
        if ($item->allowNegative) {
            $sign = [
                'cx' => ExamSheetGeometry::digitX($column, $col),
                'cy' => ExamSheetGeometry::digitY($top, 1),
                'r' => ExamSheetGeometry::DIGIT_R,
            ];
            $col++;
        }

        $columns = [];
        for ($k = 1; $k <= $item->digitColumns(); $k++, $col++) {
            $x = ExamSheetGeometry::digitX($column, $col);
            $bubbles = [];
            if ($item->allowDecimal) {
                $bubbles[] = ['value' => '.', 'cx' => $x, 'cy' => ExamSheetGeometry::digitY($top, 1), 'r' => ExamSheetGeometry::DIGIT_R];
            }
            for ($d = 0; $d <= 9; $d++) {
                $bubbles[] = ['value' => (string) $d, 'cx' => $x, 'cy' => ExamSheetGeometry::digitY($top, $d + 2), 'r' => ExamSheetGeometry::DIGIT_R];
            }
            $columns[] = ['col' => $k, 'x' => $x, 'bubbles' => $bubbles];
        }

        return [
            'sheet_no' => $item->sheetNo,
            'column' => $column,
            'band' => $band,
            'rect' => [
                'x' => ExamSheetGeometry::columnLeft($column),
                'y' => $top,
                'w' => ExamSheetGeometry::COLUMN_W,
                'h' => ExamSheetGeometry::bandHeight(),
            ],
            'sign' => $sign,
            'columns' => $columns,
        ];
    }
}
