<?php

namespace Tests\Feature\Exams;

use App\Domain\Exams\ExamWrittenLayout;
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
}
