<?php

namespace Tests\Unit\Gemini;

use App\Domain\Gemini\MultiExtraction;
use App\Domain\Gemini\SchemaValidator;
use Tests\TestCase;

/** DESIGN §21.4: the answers of a one-call-per-page output are checked one by one. */
class MultiExtractionTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function short(array $extra = []): array
    {
        return ['blank' => false, 'suspicious_instruction' => false, 'legibility' => 'clear', 'answer_text' => '20', 'key_match' => 'exact', 'error_types' => [], 'summary_th' => 'ถูก'] + $extra;
    }

    public function test_each_answer_is_checked_on_its_own(): void
    {
        $out = MultiExtraction::split(['answers' => [
            ['question_no' => 1, 'found' => true, 'answer_box' => [10, 20, 30, 40]] + self::short(),
            ['question_no' => 2, 'found' => true, 'blank' => 'no'],
            ['question_no' => 3, 'found' => false],
            ['question_no' => 4, 'found' => true, 'blank' => false, 'suspicious_instruction' => false, 'legibility' => 'clear', 'selected_options' => ['B', 'B'], 'error_types' => []],
            ['question_no' => 9, 'found' => true] + self::short(),
        ]], [
            1 => ['type' => 'short', 'criteria' => 0],
            2 => ['type' => 'short', 'criteria' => 0],
            3 => ['type' => 'short', 'criteria' => 0],
            4 => ['type' => 'mcq', 'criteria' => 0],
            5 => ['type' => 'short', 'criteria' => 0],
        ], withFound: true);

        $this->assertSame([1, 3, 4], array_keys($out['answers']), 'question 5 was left out; question 9 was never asked');
        $this->assertSame([10, 20, 30, 40], $out['answers'][1]['answer_box']);
        $this->assertSame('20', $out['answers'][1]['data']['answer_text']);
        $this->assertArrayNotHasKey('question_no', $out['answers'][1]['data']);
        $this->assertSame(['found' => false, 'data' => null, 'answer_box' => null], $out['answers'][3]);
        $this->assertSame(['B'], $out['answers'][4]['data']['selected_options']);
        $this->assertSame([2], array_keys($out['invalid']));
    }

    public function test_a_question_listed_twice_or_a_bad_box(): void
    {
        $out = MultiExtraction::split(['answers' => [
            ['question_no' => 1] + self::short(),
            ['question_no' => 1] + self::short(['answer_text' => '21']),
            ['question_no' => 2, 'answer_box' => [500, 20, 100, 40]] + self::short(),
        ]], [1 => ['type' => 'short', 'criteria' => 0], 2 => ['type' => 'short', 'criteria' => 0]], withFound: false);

        $this->assertSame([2], array_keys($out['answers']));
        $this->assertNull($out['answers'][2]['answer_box'], 'an upside-down box is dropped, not an error');
        $this->assertSame(['question_no listed more than once'], array_values($out['invalid']));
    }

    public function test_the_envelope_is_all_the_gateway_checks(): void
    {
        $this->assertSame([], SchemaValidator::validate(MultiExtraction::ENVELOPE, ['answers' => [['question_no' => 1, 'blank' => 'no']]]));
        $this->assertNotSame([], SchemaValidator::validate(MultiExtraction::ENVELOPE, ['answers' => [['blank' => false]]]));
        $this->assertNotSame([], SchemaValidator::validate(MultiExtraction::ENVELOPE, ['items' => []]));
    }
}
