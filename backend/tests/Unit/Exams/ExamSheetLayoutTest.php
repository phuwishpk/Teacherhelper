<?php

namespace Tests\Unit\Exams;

use App\Domain\Exams\ExamSheetCapacity;
use App\Domain\Exams\ExamSheetGeometry;
use App\Domain\Exams\ExamSheetItem;
use App\Domain\Exams\ExamSheetLayout;
use App\Domain\Worksheets\ArucoMarkers;
use App\Exceptions\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DESIGN §22.7, §22.8: 4 columns × 25 rows of bubble rows (100 per page,
 * 200 over two pages), numeric questions as digit blocks in 0–2 bands of
 * 11 rows at the bottom of the grid (16 at most), version bubbles on page 1
 * only, more than two pages is 422 exam_sheet_overflow, and the layout JSON
 * carries every bubble relative to the marker frame.
 */
class ExamSheetLayoutTest extends TestCase
{
    /** @return list<ExamSheetItem> */
    private static function items(int $mcq, int $numeric = 0, int $options = 4, int $digits = 3, bool $negative = false, bool $decimal = false, int $trueFalse = 0): array
    {
        $items = [];
        $n = 0;
        for ($i = 0; $i < $mcq; $i++) {
            $items[] = new ExamSheetItem(++$n, 'mcq', $options);
        }
        for ($i = 0; $i < $trueFalse; $i++) {
            $items[] = new ExamSheetItem(++$n, 'true_false', 2);
        }
        for ($i = 0; $i < $numeric; $i++) {
            $items[] = new ExamSheetItem(++$n, 'numeric', 0, $digits, $negative, $decimal);
        }

        return $items;
    }

    public function test_the_fixed_geometry_matches_the_design(): void
    {
        $this->assertSame(56.0, ExamSheetGeometry::rowY(1));
        $this->assertSame(248.0, ExamSheetGeometry::rowY(25));
        $this->assertSame(18.0, ExamSheetGeometry::columnLeft(1));
        $this->assertSame(148.5, ExamSheetGeometry::columnLeft(4));
        // Bubble i sits 11 + 6(i − 1) from the column edge; the sixth bubble of column 4 stays inside the frame.
        $this->assertSame(29.0, ExamSheetGeometry::bubbleX(1, 1));
        $this->assertSame(189.5, ExamSheetGeometry::bubbleX(4, 6));
        $this->assertLessThan(194.0, ExamSheetGeometry::bubbleX(4, 6) + ExamSheetGeometry::BUBBLE_R);
        $this->assertSame(50.0, ExamSheetGeometry::versionX(1));
        $this->assertSame(74.0, ExamSheetGeometry::versionX(4));
        // Bands take the last 11 rows (one band) or 22 rows (two bands) and end with row 25.
        $this->assertSame(ExamSheetGeometry::rowTop(15), ExamSheetGeometry::bandTop(1, 0));
        $this->assertSame(ExamSheetGeometry::rowTop(4), ExamSheetGeometry::bandTop(2, 0));
        $this->assertSame(252.0, ExamSheetGeometry::bandTop(2, 1) + ExamSheetGeometry::bandHeight());
        // Eleven digit rows fit under the 12 mm block header, inside the 88 mm band.
        $top = ExamSheetGeometry::bandTop(1, 0);
        $this->assertLessThan($top + 88, ExamSheetGeometry::digitY($top, 11) + ExamSheetGeometry::DIGIT_R);
        // Seven block columns (sign + 5 digits + point) are 39.2 mm wide and fit a grid column.
        $this->assertEqualsWithDelta(39.2, 7 * ExamSheetGeometry::DIGIT_COL_STEP, 1e-9);
        $this->assertLessThan(ExamSheetGeometry::columnLeft(2), ExamSheetGeometry::digitX(1, 7) + ExamSheetGeometry::DIGIT_R);
    }

    public function test_one_hundred_rows_fill_one_page_column_by_column(): void
    {
        $plan = ExamSheetLayout::plan(self::items(100), 1);

        $this->assertSame(1, $plan->pageCount());
        $rows = collect($plan->pages[0]['rows'])->keyBy('sheet_no');
        $this->assertCount(100, $rows);
        $this->assertSame([1, 1], [$rows[1]['column'], $rows[1]['row']]);
        $this->assertSame([1, 25], [$rows[25]['column'], $rows[25]['row']]);
        $this->assertSame([2, 1], [$rows[26]['column'], $rows[26]['row']]);
        $this->assertSame([4, 25], [$rows[100]['column'], $rows[100]['row']]);
        $this->assertSame(248.0, $rows[100]['bubbles'][0]['cy']);
        $this->assertSame(['ก', 'ข', 'ค', 'ง'], array_column($rows[1]['bubbles'], 'label'));
        $this->assertSame([1, 2, 3, 4], array_column($rows[1]['bubbles'], 'value'));
        $this->assertSame(ExamSheetGeometry::bubbleX(2, 3), $rows[26]['bubbles'][2]['cx']);
        $this->assertSame([], $plan->pages[0]['version'], 'one version has no version bubbles');
    }

    public function test_two_hundred_rows_take_two_pages_and_page_two_continues_the_numbers(): void
    {
        $plan = ExamSheetLayout::plan(self::items(150, 0, 6, trueFalse: 50), 3);

        $this->assertSame(2, $plan->pageCount());
        $this->assertFalse($plan->overflows());
        $this->assertSame(range(1, 100), array_column($plan->pages[0]['rows'], 'sheet_no'));
        $this->assertSame(range(101, 200), array_column($plan->pages[1]['rows'], 'sheet_no'));
        $this->assertSame([1, 1], [$plan->pages[1]['rows'][0]['column'], $plan->pages[1]['rows'][0]['row']]);
        // Version bubbles on page 1 only.
        $this->assertSame(['ก', 'ข', 'ค'], array_column($plan->pages[0]['version'], 'label'));
        $this->assertSame([], $plan->pages[1]['version']);
        // true_false rows use bubbles 1–2 labelled ถ ผ.
        $tf = collect($plan->pages[1]['rows'])->firstWhere('sheet_no', 151);
        $this->assertSame(['ถ', 'ผ'], array_column($tf['bubbles'], 'label'));
    }

    /**
     * @return array<string, array{int, int, list<array{rows: int, blocks: int, bands: int}>}>
     */
    public static function bandCases(): array
    {
        return [
            'no numeric' => [30, 0, [['rows' => 30, 'blocks' => 0, 'bands' => 0]]],
            'one band' => [56, 4, [['rows' => 56, 'blocks' => 4, 'bands' => 1]]],
            'no band fits under 60 rows' => [60, 3, [['rows' => 60, 'blocks' => 0, 'bands' => 0], ['rows' => 0, 'blocks' => 3, 'bands' => 1]]],
            'two bands' => [12, 8, [['rows' => 12, 'blocks' => 8, 'bands' => 2]]],
            'sixteen numeric on two pages' => [0, 16, [['rows' => 0, 'blocks' => 8, 'bands' => 2], ['rows' => 0, 'blocks' => 8, 'bands' => 2]]],
            'one band under the rows, the rest on page 2' => [20, 10, [['rows' => 20, 'blocks' => 4, 'bands' => 1], ['rows' => 0, 'blocks' => 6, 'bands' => 2]]],
            'compact fallback' => [60, 10, [['rows' => 12, 'blocks' => 8, 'bands' => 2], ['rows' => 48, 'blocks' => 2, 'bands' => 1]]],
        ];
    }

    /**
     * @param  list<array{rows: int, blocks: int, bands: int}>  $expected
     */
    #[DataProvider('bandCases')]
    public function test_pages_take_zero_to_two_digit_bands_after_the_rows(int $rows, int $numeric, array $expected): void
    {
        $this->assertSame($expected, ExamSheetCapacity::split($rows, $numeric));
        $plan = ExamSheetLayout::plan(self::items($rows, $numeric), 1);
        $this->assertSame(count($expected), $plan->pageCount());
        foreach ($expected as $i => $page) {
            $this->assertCount($page['rows'], $plan->pages[$i]['rows']);
            $this->assertCount($page['blocks'], $plan->pages[$i]['blocks']);
            $this->assertSame($page['bands'], $plan->pages[$i]['bands']);
        }
        $this->assertSame(ExamSheetCapacity::of($rows, $numeric)['pages'], $plan->pageCount());
    }

    public function test_sixty_mcq_and_five_numeric_keep_question_order(): void
    {
        $plan = ExamSheetLayout::plan(self::items(60, 5), 1);

        $this->assertSame(2, $plan->pageCount());
        [$one, $two] = $plan->pages;
        $this->assertSame(range(1, 60), array_column($one['rows'], 'sheet_no'));
        $this->assertSame([], $one['blocks']);
        $this->assertSame(0, $one['bands']);
        $rows = collect($one['rows'])->keyBy('sheet_no');
        $this->assertSame([1, 25], [$rows[25]['column'], $rows[25]['row']]);
        $this->assertSame([2, 1], [$rows[26]['column'], $rows[26]['row']]);
        $this->assertSame([3, 10], [$rows[60]['column'], $rows[60]['row']]);
        $this->assertSame([], $two['rows']);
        $this->assertSame(range(61, 65), array_column($two['blocks'], 'sheet_no'));
        $this->assertSame([[1, 0], [2, 0], [3, 0], [4, 0], [1, 1]], array_map(fn (array $b) => [$b['column'], $b['band']], $two['blocks']));
    }

    public function test_blocks_under_the_rows_come_after_them(): void
    {
        $plan = ExamSheetLayout::plan(self::items(40, 7), 1);

        [$one, $two] = $plan->pages;
        $this->assertSame(range(1, 40), array_column($one['rows'], 'sheet_no'));
        $this->assertSame([1, 14], [$one['rows'][13]['column'], $one['rows'][13]['row']]);
        $this->assertSame([2, 1], [$one['rows'][14]['column'], $one['rows'][14]['row']]);
        $this->assertSame(range(41, 44), array_column($one['blocks'], 'sheet_no'));
        $this->assertSame(range(45, 47), array_column($two['blocks'], 'sheet_no'));
    }

    public function test_rows_and_blocks_never_overlap(): void
    {
        foreach ([[12, 8], [56, 4], [30, 2], [40, 7]] as [$rows, $numeric]) {
            $page = ExamSheetLayout::plan(self::items($rows, $numeric), 1)->pages[0];
            $lowestRow = max(array_map(fn (array $r) => $r['rect']['y'] + $r['rect']['h'], $page['rows']));
            $highestBlock = min(array_map(fn (array $b) => $b['rect']['y'], $page['blocks']));
            $this->assertLessThanOrEqual($highestBlock, $lowestRow, "{$rows} rows + {$numeric} numeric");
        }
    }

    public function test_a_digit_block_has_a_sign_column_digit_columns_and_a_point_column(): void
    {
        $plan = ExamSheetLayout::plan(self::items(0, 1, digits: 5, negative: true, decimal: true), 1);
        $block = $plan->pages[0]['blocks'][0];

        $this->assertNotNull($block['sign']);
        $this->assertCount(6, $block['columns'], '5 digits + 1 column for the point');
        $this->assertSame(['.', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], array_column($block['columns'][0]['bubbles'], 'value'));
        // The sign bubble is on the first bubble row, left of the first digit column; "." shares that row.
        $this->assertSame($block['sign']['cy'], $block['columns'][0]['bubbles'][0]['cy']);
        $this->assertEqualsWithDelta(ExamSheetGeometry::DIGIT_COL_STEP, $block['columns'][0]['x'] - $block['sign']['cx'], 1e-9);
        $this->assertEqualsWithDelta(10 * ExamSheetGeometry::DIGIT_ROW_STEP, $block['columns'][0]['bubbles'][10]['cy'] - $block['sign']['cy'], 1e-9);

        $plain = ExamSheetLayout::plan(self::items(0, 1, digits: 2), 1)->pages[0]['blocks'][0];
        $this->assertNull($plain['sign']);
        $this->assertCount(2, $plain['columns']);
        $this->assertSame(range(0, 9), array_map('intval', array_column($plain['columns'][0]['bubbles'], 'value')));
    }

    public function test_more_than_two_pages_is_exam_sheet_overflow(): void
    {
        ExamSheetLayout::assertFits(ExamSheetLayout::plan(self::items(0, 16), 1));

        $plan = ExamSheetLayout::plan(self::items(0, 17), 1);
        $this->assertSame(3, $plan->pageCount());
        try {
            ExamSheetLayout::assertFits($plan);
            $this->fail('17 numeric questions need three pages');
        } catch (ApiException $e) {
            $this->assertSame('exam_sheet_overflow', $e->errorCode);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_the_layout_json_has_the_exam_regions_relative_to_the_marker_frame(): void
    {
        $plan = ExamSheetLayout::plan(self::items(3, 1, 4, 3, false, true), 2);
        $pages = $plan->toLayoutPages(301, 1, ArucoMarkers::load());

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $this->assertSame(301, $page['assignment_id']);
        $this->assertSame(1, $page['version']);
        $this->assertSame([1, 1], [$page['page'], $page['page_count']]);
        $this->assertSame('exam', $page['sheet']);
        $this->assertSame(['dictionary' => 'DICT_4X4_50', 'ids' => [0, 1, 2, 3], 'size_mm' => 12], $page['marker']);
        $this->assertSame(['x' => 16, 'y' => 16, 'w' => 178, 'h' => 265], $page['frame_mm']);
        $this->assertSame(['x' => 0.0056, 'y' => 0.1132, 'w' => 0.9888, 'h' => 0.7925], $page['answer_area']);

        $regions = collect($page['regions'])->keyBy('region_id');
        $this->assertSame(['version', 's1', 's2', 's3', 's4'], $regions->keys()->all());
        $this->assertSame('version_bubbles', $regions['version']['kind']);
        $this->assertSame(
            ['value' => 2, 'label' => 'ข', 'cx' => round((58 - 16) / 178, 4), 'cy' => round((38.5 - 16) / 265, 4), 'r' => round(2.1 / 178, 4)],
            $regions['version']['bubbles'][1],
        );

        $row = $regions['s2'];
        $this->assertSame('omr_row', $row['kind']);
        $this->assertSame(2, $row['sheet_no']);
        $this->assertSame(
            ['value' => 1, 'label' => 'ก', 'cx' => round((29 - 16) / 178, 4), 'cy' => round((64 - 16) / 265, 4), 'r' => round(2.1 / 178, 4)],
            $row['bubbles'][0],
        );

        $block = $regions['s4'];
        $this->assertSame('digit_block', $block['kind']);
        $this->assertSame(4, $block['sheet_no']);
        $this->assertNull($block['sign']);
        $this->assertSame([1, 2, 3, 4], array_column($block['columns'], 'col'));
        $this->assertSame('.', $block['columns'][0]['bubbles'][0]['value']);
        $this->assertSame(['value', 'cx', 'cy', 'r'], array_keys($block['columns'][0]['bubbles'][0]));
        foreach ([$row['rect'], $block['rect']] as $rect) {
            $this->assertGreaterThanOrEqual(0, $rect['x']);
            $this->assertLessThanOrEqual(1, $rect['x'] + $rect['w']);
            $this->assertLessThanOrEqual(1, $rect['y'] + $rect['h']);
        }
    }
}
