<?php

namespace Tests\Unit\AnswerKeys;

use App\Domain\AnswerKeys\AnswerKeyResult;
use App\Domain\Documents\CostEstimate;
use App\Domain\Gemini\GeminiException;
use Tests\TestCase;

/** DESIGN §19.5: Gemini's answer key normalised to the questions' key shapes, and the cost estimate. */
class AnswerKeyResultTest extends TestCase
{
    public function test_every_type_becomes_the_key_the_questions_store(): void
    {
        $result = AnswerKeyResult::fromGemini(AnswerKeyResult::KIND_READ, ['notes_th' => ' ข้อ 5 อ่านไม่ออก ', 'questions' => [
            ['question_no' => 3, 'type' => 'show_work', 'prompt_text' => ' 3 × 4 ', 'max_points' => 150, 'accepted_answers' => [], 'numeric_value' => 12.0, 'reference_steps' => ['3 × 4', '', '= 12']],
            ['question_no' => 1, 'type' => 'mcq', 'prompt_text' => 'ข้อใด', 'correct_option' => 'c', 'confidence' => 'high'],
            ['question_no' => 2, 'type' => 'short', 'prompt_text' => 'เมืองหลวง', 'accepted_answers' => ['กรุงเทพฯ', 'กรุงเทพฯ', ' กรุงเทพมหานคร '], 'max_points' => 0],
            ['question_no' => 4, 'type' => 'open', 'prompt_text' => 'อธิบาย', 'model_answer' => 'เพราะคลอโรฟิลล์', 'key_points' => ['คลอโรฟิลล์'], 'correct_option' => 'A'],
            ['question_no' => 5, 'type' => 'short', 'prompt_text' => 'อ่านไม่ออก'],
            ['question_no' => 1, 'type' => 'short', 'prompt_text' => 'ซ้ำ'],
            ['question_no' => 0, 'type' => 'short', 'prompt_text' => 'ไม่มีเลขข้อ'],
            ['question_no' => 6, 'type' => 'essay', 'prompt_text' => 'ชนิดแปลก'],
        ]]);

        $this->assertSame([1, 2, 3, 4, 5], array_column($result->questions, 'question_no'));
        $this->assertSame(['correct' => 'C'], $result->questions[0]['answer_key']);
        $this->assertSame('high', $result->questions[0]['confidence']);
        $this->assertSame(['accepted' => ['กรุงเทพฯ', 'กรุงเทพมหานคร']], $result->questions[1]['answer_key']);
        $this->assertNull($result->questions[1]['max_points'], 'no points read');
        $this->assertSame(['final' => ['accepted' => ['12'], 'numeric' => ['value' => 12.0, 'abs_tol' => 0.0]], 'reference_steps' => ['3 × 4', '= 12']], $result->questions[2]['answer_key']);
        $this->assertSame([100.0, '3 × 4'], [$result->questions[2]['max_points'], $result->questions[2]['prompt_text']]);
        $this->assertNull($result->questions[3]['answer_key'], 'open is graded with a rubric');
        $this->assertSame("เพราะคลอโรฟิลล์\n\nประเด็นสำคัญ:\n- คลอโรฟิลล์", $result->questions[3]['model_answer']);
        $this->assertNull($result->questions[4]['answer_key'], 'no answer: the teacher fills it in');
        $this->assertSame('ข้อ 5 อ่านไม่ออก', $result->notes);

        $again = AnswerKeyResult::fromArray($result->toArray());
        $this->assertEquals($result, $again);
    }

    public function test_output_without_any_usable_question_is_invalid(): void
    {
        $this->expectException(GeminiException::class);

        AnswerKeyResult::fromGemini(AnswerKeyResult::KIND_DRAFT, ['questions' => [['question_no' => 1, 'type' => 'drawing', 'prompt_text' => 'x']]]);
    }

    public function test_the_estimate_follows_the_document_resolution_and_the_prices_in_env(): void
    {
        config(['services.gemini.media.document' => 'medium', 'services.gemini.price_input_per_m' => null, 'services.gemini.price_output_per_m' => '3', 'eduvision.usd_thb_rate' => '33']);
        $this->assertSame(['input_tokens' => 10 * 560 + 1500, 'output_tokens' => 20 * 150, 'thb' => null], CostEstimate::forPages(10, 20));

        config(['services.gemini.price_input_per_m' => '1', 'services.gemini.media.document' => 'low']);
        $this->assertSame(['input_tokens' => 2 * 280 + 1500, 'output_tokens' => 10 * 150, 'thb' => round((2060 * 1 + 1500 * 3) / 1e6 * 33, 2)], CostEstimate::forPages(2));
        $this->assertSame(CostEstimate::MAX_OUTPUT, CostEstimate::forPages(30, 500)['output_tokens']);
    }
}
