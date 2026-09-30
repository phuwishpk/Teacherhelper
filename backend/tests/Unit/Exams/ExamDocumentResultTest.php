<?php

namespace Tests\Unit\Exams;

use App\Domain\Exams\ExamDocumentResult;
use App\Domain\Exams\ExamFigures;
use App\Domain\Gemini\GeminiException;
use PHPUnit\Framework\TestCase;

/** DESIGN §22.4: exam_read output normalised into the cached, school-wide form. */
class ExamDocumentResultTest extends TestCase
{
    private const FILES = [
        ['sha256' => 'aaa', 'pages' => 1, 'page_offset' => 0],
        ['sha256' => 'bbb', 'pages' => 2, 'page_offset' => 4],
    ];

    public function test_labels_become_original_positions_and_numbers_their_canonical_form(): void
    {
        $result = ExamDocumentResult::fromGemini(['sections' => [
            ['type' => 'mcq', 'questions' => [
                ['number' => 1, 'text' => ' โจทย์ ', 'options' => [['label' => 'A', 'text' => 'x'], ['label' => 'B', 'text' => 'y'], ['label' => 'C', 'text' => 'z']], 'answer' => ['options' => ['(c)', 'ข้อ A.']]],
                ['number' => 2, 'text' => 'สอง', 'options' => [['label' => 'ก'], ['label' => 'ข']], 'answer' => ['options' => ['ง', 'ค']]],
                ['number' => 3, 'text' => 'สาม', 'answer' => ['options' => ['Z']], 'lock_options' => true],
            ]],
            ['type' => 'true_false', 'questions' => [
                ['number' => 4, 'text' => 'จริง', 'answer' => ['options' => ['True']]],
                ['number' => 5, 'text' => 'เท็จ', 'answer' => ['options' => ['ผิด']]],
                ['number' => 6, 'text' => 'ทั้งสอง', 'answer' => ['options' => ['ถ', 'ผ']]],
            ]],
            ['type' => 'numeric', 'questions' => [
                ['number' => 7, 'text' => 'เลข', 'answer' => ['values' => ['-00.50', '1,250', 'abc']]],
            ]],
            ['type' => 'essay', 'questions' => [['number' => 9, 'text' => 'x']]],
            ['type' => 'mcq', 'questions' => []],
        ], 'skipped' => [['number' => 8, 'reason_th' => ''], ['reason_th' => 'no number']]], self::FILES);

        $this->assertCount(3, $result['sections'], 'unknown types and empty sections are dropped');
        [$mcq, $tf, $numeric] = $result['sections'];
        $this->assertSame(3, $mcq['option_count'], 'the most options any question has');
        $this->assertSame('โจทย์', $mcq['questions'][0]['text']);
        $this->assertSame(['accepted_options' => [1, 3]], $mcq['questions'][0]['answer']);
        $this->assertSame(['accepted_options' => [3]], $mcq['questions'][1]['answer'], 'ง is past the 3 options');
        $this->assertNull($mcq['questions'][2]['answer']);
        $this->assertTrue($mcq['questions'][2]['lock_options']);
        $this->assertSame([1, 2, 3], array_column($mcq['questions'][1]['options'], 'position'));
        $this->assertSame([['accepted_options' => [1]], ['accepted_options' => [2]], null], array_column($tf['questions'], 'answer'));
        $this->assertSame([], $tf['questions'][0]['options']);
        $this->assertSame(['accepted_values' => ['-0.5', '1250']], $numeric['questions'][0]['answer']);
        $this->assertSame(['digits' => 4, 'allow_negative' => true, 'allow_decimal' => true], $numeric['numeric'], 'the block fits the printed key');
        $this->assertSame([['number' => 8, 'reason_th' => 'ฝนคำตอบไม่ได้']], $result['skipped']);
    }

    public function test_figures_name_the_file_by_hash_and_the_page_within_the_whole_file(): void
    {
        $result = ExamDocumentResult::fromGemini(['sections' => [['type' => 'mcq', 'option_count' => 2, 'questions' => [
            ['number' => 1, 'text' => 'a', 'figure' => ['file' => 2, 'page' => 2, 'box_2d' => [10, 20, 300, 400]],
                'options' => [['label' => 'ก', 'figure' => ['file' => 1, 'page' => 1, 'box_2d' => [0, 0, 1000, 1000]]], ['label' => 'ข', 'figure' => ['file' => 3, 'page' => 1, 'box_2d' => [0, 0, 10, 10]]]]],
            ['number' => 2, 'text' => 'b', 'figure' => ['file' => 1, 'page' => 2, 'box_2d' => [0, 0, 10, 10]]],
            ['number' => 3, 'text' => 'c', 'figure' => ['file' => 1, 'page' => 1, 'box_2d' => [300, 0, 100, 10]]],
        ]]], 'skipped' => []], self::FILES);

        $questions = $result['sections'][0]['questions'];
        $this->assertSame(['sha256' => 'bbb', 'page' => 6, 'box_2d' => [10, 20, 300, 400]], $questions[0]['figure']);
        $this->assertSame(['sha256' => 'aaa', 'page' => 1, 'box_2d' => [0, 0, 1000, 1000]], $questions[0]['options'][0]['figure']);
        $this->assertNull($questions[0]['options'][1]['figure'], 'file 3 was not sent');
        $this->assertNull($questions[1]['figure'], 'file 1 has one page');
        $this->assertNull($questions[2]['figure'], 'an upside-down box');
        $this->assertSame(1, ExamDocumentResult::questionCount(['sections' => [['questions' => [[]]]]]));
    }

    public function test_nothing_usable_is_invalid_output_but_only_skipped_questions_are_fine(): void
    {
        $only = ExamDocumentResult::fromGemini(['sections' => [], 'skipped' => [['number' => 1, 'reason_th' => 'ข้อเขียน']]], self::FILES);
        $this->assertSame([], $only['sections']);

        $this->expectException(GeminiException::class);
        ExamDocumentResult::fromGemini(['sections' => [['type' => 'mcq', 'questions' => []]], 'skipped' => []], self::FILES);
    }

    public function test_a_box_is_four_ordered_numbers_of_0_to_1000(): void
    {
        $this->assertSame([1, 2, 300, 400], ExamFigures::box([1, 2.0, '300', 400]));
        foreach ([null, [1, 2, 3], [0, 0, 1001, 10], [-1, 0, 10, 10], [0, 0, 4, 100], ['a', 0, 10, 10], ['x' => 1, 'y' => 2, 'z' => 3, 'w' => 4]] as $box) {
            $this->assertNull(ExamFigures::box($box), json_encode($box));
        }
    }
}
