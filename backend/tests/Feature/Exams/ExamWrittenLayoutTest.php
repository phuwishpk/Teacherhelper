<?php

namespace Tests\Feature\Exams;

use App\Domain\Exams\ExamSheetLayout;
use App\Domain\Exams\ExamWrittenLayout;
use App\Domain\Worksheets\ArucoMarkers;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.20.3: written questions are laid out top-down in the content
 * area of the written pages, a label of 7 mm above each box, 6 mm between
 * blocks, and a block is never split across pages.
 */
class ExamWrittenLayoutTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    private Assignment $exam;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeExamWorld();
        $this->exam = $this->createExam();
    }

    public function test_blocks_stack_down_the_page_and_a_block_that_does_not_fit_starts_the_next(): void
    {
        $this->addSection($this->exam, ['type' => 'open', 'question_count' => 3, 'default_points' => 5]);

        $blocks = ExamWrittenLayout::blocks($this->exam);

        $this->assertSame([0, 0, 1], array_column($blocks, 'page_index'));
        $this->assertSame([48.0, 141.0, 48.0], array_column($blocks, 'label_top'));
        $this->assertSame(55.0, $blocks[0]['region']->rect['y'], 'the box starts below the 7 mm label');
        $this->assertSame(80.0, $blocks[0]['region']->rect['h'], '8 lines of 10 mm');
        $this->assertSame([1, 2, 3], array_column($blocks, 'number'));
        $this->assertSame(2, ExamWrittenLayout::pageCount($this->exam));
    }

    public function test_a_show_work_block_adds_the_final_answer_box_and_fits_a_page_at_fifteen_lines(): void
    {
        $section = $this->addSection($this->exam, ['type' => 'show_work', 'question_count' => 2]);
        Question::query()->where('section_id', $section['id'])->update(['answer_lines' => 15]);

        $blocks = ExamWrittenLayout::blocks($this->exam);

        $this->assertSame([0, 1], array_column($blocks, 'page_index'), '176 mm blocks: one to a page');
        $this->assertSame(208.0, $blocks[0]['region']->final['y'], 'below the 150 mm of lines and a 3 mm gap');
        $this->assertSame(16.0, $blocks[0]['region']->final['h']);
    }

    public function test_choice_questions_are_not_laid_out_and_no_written_question_means_no_pages(): void
    {
        $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 4]);

        $this->assertSame([], ExamWrittenLayout::blocks($this->exam));
        $this->assertSame(0, ExamWrittenLayout::pageCount($this->exam));

        $this->addSection($this->exam, ['type' => 'open', 'question_count' => 1]);
        $blocks = ExamWrittenLayout::blocks($this->exam);
        $this->assertSame([5], array_column($blocks, 'number'), 'numbers are the question positions after the bubble questions');
    }

    public function test_the_layout_json_appends_the_written_pages_after_the_bubble_pages(): void
    {
        $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 6]);
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 5]);

        $plan = ExamSheetLayout::forExam($this->exam);
        $pages = $plan->toLayoutPages($this->exam->id, 1, ArucoMarkers::load());

        $this->assertSame([1, 3, 4], [$plan->bubblePageCount(), $plan->writtenPageCount(), $plan->pageCount()]);
        $this->assertSame(['exam', 'written', 'written', 'written'], array_column($pages, 'sheet'));
        $this->assertSame([1, 2, 3, 4], array_column($pages, 'page'));
        $this->assertSame([4, 4, 4, 4], array_column($pages, 'page_count'), 'every page knows the whole sheet');
        $ids = array_column($open['questions'], 'id');
        $this->assertSame(array_map(fn ($id) => "q{$id}", array_slice($ids, 0, 2)), array_column($pages[1]['regions'], 'region_id'));
        $first = $pages[1]['regions'][0];
        $this->assertSame(['lines', $ids[0], 8], [$first['kind'], $first['question_id'], $first['line_count']]);
        foreach ($pages[1]['regions'] as $region) {
            $r = $region['rect'];
            $this->assertGreaterThanOrEqual(0, $r['x']);
            $this->assertGreaterThanOrEqual(0, $r['y']);
            $this->assertLessThanOrEqual(1, $r['x'] + $r['w']);
            $this->assertLessThanOrEqual(1, $r['y'] + $r['h']);
        }
        $this->assertCount(1, $pages[3]['regions']);
    }

    public function test_a_show_work_region_carries_its_final_answer_box(): void
    {
        $this->addSection($this->exam, ['type' => 'show_work', 'question_count' => 1]);

        $region = ExamSheetLayout::forExam($this->exam)->toLayoutPages($this->exam->id, 1, ArucoMarkers::load())[0]['regions'][0];

        $this->assertSame('lines', $region['kind']);
        $this->assertArrayHasKey('final_answer', $region);
        $this->assertGreaterThan($region['rect']['y'] + $region['rect']['h'], $region['final_answer']['rect']['y']);
    }

    public function test_an_exam_without_written_questions_has_the_layout_it_always_had(): void
    {
        $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 6]);

        $plan = ExamSheetLayout::forExam($this->exam);
        $pages = $plan->toLayoutPages($this->exam->id, 1, ArucoMarkers::load());

        $this->assertSame(0, $plan->writtenPageCount());
        $this->assertSame(['exam'], array_column($pages, 'sheet'));
        $this->assertSame([1], array_column($pages, 'page_count'));
        $this->assertEquals(
            ExamSheetLayout::plan(ExamSheetLayout::items($this->exam), 1)->toLayoutPages($this->exam->id, 1, ArucoMarkers::load()),
            $pages,
        );
    }

    public function test_written_pages_over_the_limit_are_exam_sheet_overflow(): void
    {
        $this->addSection($this->exam, ['type' => 'open', 'question_count' => 12]);

        try {
            ExamSheetLayout::forExam($this->exam);
            $this->fail('twelve 8-line boxes need six pages');
        } catch (ApiException $e) {
            $this->assertSame('exam_sheet_overflow', $e->errorCode);
            $this->assertSame('ข้อเขียนยาวเกิน 4 หน้า ลดจำนวนข้อหรือจำนวนบรรทัด', $e->getMessage());
        }
        $this->assertSame([], ExamSheetLayout::writtenPageOf($this->exam), 'the booklet falls back to no page number');
    }

    public function test_each_written_question_knows_its_sheet_page(): void
    {
        $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 6]);
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 5]);

        $pages = ExamSheetLayout::writtenPageOf($this->exam);

        $this->assertSame(
            array_combine(array_column($open['questions'], 'id'), [2, 2, 3, 3, 4]),
            $pages,
        );
    }
}
