<?php

namespace Tests\Feature\Worksheets;

use App\Domain\Worksheets\ArucoMarkers;
use App\Domain\Worksheets\LayoutBuilder;
use App\Domain\Worksheets\WorksheetGeometry;
use App\Domain\Worksheets\WorksheetLayoutException;
use Tests\TestCase;

/**
 * DESIGN §5.1, §5.3: layout JSON shape, frame-relative coordinates, region
 * kinds, real (measured) prompt heights and whole-question pagination.
 */
class LayoutBuilderTest extends TestCase
{
    use WorksheetFixtures;

    private function builder(): LayoutBuilder
    {
        return app(LayoutBuilder::class);
    }

    public function test_page_json_has_the_design_shape(): void
    {
        $pages = $this->builder()->build($this->assignmentWith([$this->question('mcq')]), 2);

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $this->assertSame(['assignment_id', 'version', 'page', 'page_count', 'marker', 'frame_mm', 'regions'], array_keys($page));
        $this->assertSame(123, $page['assignment_id']);
        $this->assertSame(2, $page['version']);
        $this->assertSame(1, $page['page']);
        $this->assertSame(1, $page['page_count']);
        $this->assertSame(['dictionary' => 'DICT_4X4_50', 'ids' => [0, 1, 2, 3], 'size_mm' => 12], $page['marker']);
        $this->assertSame(['x' => 16, 'y' => 16, 'w' => 178, 'h' => 265], $page['frame_mm']);
    }

    public function test_each_question_type_gets_its_region_kind(): void
    {
        $mcq = $this->question('mcq');
        $short = $this->question('short');
        $text = $this->question('short', ['is_numeric' => false]);
        $work = $this->question('show_work');
        $open = $this->question('open');

        $regions = $this->builder()->build($this->assignmentWith([$mcq, $short, $text, $work, $open]), 1)[0]['regions'];
        $byId = collect($regions)->keyBy('question_id');

        $m = $byId[$mcq->id];
        $this->assertSame('q'.$mcq->id, $m['region_id']);
        $this->assertSame('mcq', $m['kind']);
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($m['bubbles'], 'option'));
        foreach ($m['bubbles'] as $bubble) {
            $this->assertEqualsWithDelta($m['bubbles'][0]['cy'], $bubble['cy'], 1e-9, 'bubbles share one row');
            $this->assertGreaterThan($m['rect']['x'], $bubble['cx'] - $bubble['r']);
            $this->assertLessThan($m['rect']['x'] + $m['rect']['w'], $bubble['cx'] + $bubble['r']);
            $this->assertEqualsWithDelta(WorksheetGeometry::MCQ_BUBBLE_R / 178, $bubble['r'], 1e-4, 'r is relative to the frame width');
        }

        $this->assertSame('box', $byId[$short->id]['kind']);
        $this->assertTrue($byId[$short->id]['numeric']);
        $this->assertFalse($byId[$text->id]['numeric']);

        $w = $byId[$work->id];
        $this->assertSame('lines', $w['kind']);
        $this->assertSame(4, $w['line_count']);
        $this->assertTrue($w['final_answer']['numeric']);
        $this->assertGreaterThan($w['rect']['y'] + $w['rect']['h'], $w['final_answer']['rect']['y'], 'final answer box sits below the lines');

        $o = $byId[$open->id];
        $this->assertSame('lines', $o['kind']);
        $this->assertSame(5, $o['line_count']);
        $this->assertArrayNotHasKey('final_answer', $o);
    }

    public function test_coordinates_are_frame_relative_and_regions_flow_down_without_overlap(): void
    {
        $questions = [$this->question('mcq'), $this->question('short'), $this->question('show_work'), $this->question('open', ['answer_lines' => 3])];
        $regions = $this->builder()->build($this->assignmentWith($questions), 1)[0]['regions'];

        $previousBottom = (WorksheetGeometry::CONTENT_TOP - 16) / 265;
        foreach ($regions as $region) {
            $rects = [$region['rect']];
            if (isset($region['final_answer'])) {
                $rects[] = $region['final_answer']['rect'];
            }
            foreach ($rects as $rect) {
                $this->assertGreaterThanOrEqual(0, $rect['x']);
                $this->assertLessThanOrEqual(1, $rect['x'] + $rect['w']);
                $this->assertGreaterThan($previousBottom, $rect['y'], 'regions do not overlap and keep question order');
                $this->assertLessThanOrEqual((WorksheetGeometry::CONTENT_BOTTOM - 16) / 265 + 1e-4, $rect['y'] + $rect['h']);
                $previousBottom = $rect['y'] + $rect['h'];
            }
        }

        // 70 mm box in a 178 mm frame, right-aligned to the content edge.
        $box = $regions[1]['rect'];
        $this->assertEqualsWithDelta(70 / 178, $box['w'], 1e-4);
        $this->assertEqualsWithDelta((192 - 16) / 178, $box['x'] + $box['w'], 1e-4);
    }

    public function test_prompt_height_comes_from_the_real_typesetting(): void
    {
        $short = $this->builder()->plan($this->assignmentWith([$this->question('short')]));
        $long = $this->builder()->plan($this->assignmentWith([$this->question('short', ['prompt_text' => $this->longThaiPrompt(3)])]));

        $shortHeight = $short->pages[0][0]->promptHeight;
        $longHeight = $long->pages[0][0]->promptHeight;

        // One Thai line at 13 pt with line-height 1.4 is about 6.4 mm; three
        // repetitions of the sentence wrap onto several lines.
        $this->assertEqualsWithDelta(6.4, $shortHeight, 0.3);
        $this->assertGreaterThan($shortHeight * 3, $longHeight);
    }

    public function test_a_question_that_does_not_fit_moves_whole_to_the_next_page(): void
    {
        $questions = [];
        for ($i = 0; $i < 7; $i++) {
            $questions[] = $this->question('show_work', ['answer_lines' => 5]);
        }
        $plan = $this->builder()->plan($this->assignmentWith($questions));
        $pages = $plan->toLayoutPages(123, 1, ArucoMarkers::load());

        $this->assertGreaterThan(1, count($pages));
        $seen = [];
        foreach ($pages as $index => $page) {
            $this->assertSame($index + 1, $page['page']);
            $this->assertSame(count($pages), $page['page_count']);
            foreach ($page['regions'] as $region) {
                $seen[] = $region['question_id'];
                // Lines and final answer box of one question are on the same page.
                $this->assertLessThanOrEqual(
                    (WorksheetGeometry::CONTENT_BOTTOM - 16) / 265 + 1e-4,
                    $region['final_answer']['rect']['y'] + $region['final_answer']['rect']['h'],
                );
            }
        }
        $this->assertSame(array_map(fn ($q) => $q->id, $questions), $seen, 'every question once, in order');

        // The first question of page 2 starts at the top of the flow area.
        $this->assertEqualsWithDelta(WorksheetGeometry::CONTENT_TOP, $plan->pages[1][0]->top, 1e-9);
    }

    public function test_a_question_taller_than_a_page_is_rejected(): void
    {
        $this->expectException(WorksheetLayoutException::class);

        $this->builder()->plan($this->assignmentWith([
            $this->question('show_work', ['answer_lines' => 15, 'prompt_text' => $this->longThaiPrompt(20)]),
        ]));
    }

    public function test_the_layout_is_deterministic(): void
    {
        $questions = [$this->question('mcq'), $this->question('show_work', ['prompt_text' => $this->longThaiPrompt(2)]), $this->question('open')];

        $this->assertSame(
            $this->builder()->build($this->assignmentWith($questions), 1),
            $this->builder()->build($this->assignmentWith($questions), 1),
        );
    }
}
