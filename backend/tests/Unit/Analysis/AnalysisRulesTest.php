<?php

namespace Tests\Unit\Analysis;

use App\Domain\Analysis\AnalysisInput;
use App\Domain\Analysis\StudentAnalysisRequests;
use App\Domain\Gemini\GeminiBatch;
use App\Domain\Gemini\GeminiException;
use App\Models\Skill;
use PHPUnit\Framework\TestCase;

/** DESIGN §20.5 selection rules, the student_analysis check and the Batch API states (§20.8). */
class AnalysisRulesTest extends TestCase
{
    private static function skill(int $id, string $code): Skill
    {
        $skill = new Skill;
        $skill->forceFill(['id' => $id, 'code' => $code, 'name' => 'ตัวชี้วัด '.$id]);

        return $skill;
    }

    /**
     * @param  list<array{0: int, 1: float, 2: int, 3?: int}>  $rows  [id, value, n_obs, practice]
     */
    private static function input(array $rows): AnalysisInput
    {
        $entries = array_map(fn (array $r) => ['skill' => self::skill($r[0], 'ค 1.1 ป.5/'.$r[0]), 'value' => $r[1], 'n_obs' => $r[2], 'practice_items' => $r[3] ?? 0], $rows);
        usort($entries, fn (array $a, array $b) => $a['value'] <=> $b['value'] ?: $a['skill']->id <=> $b['skill']->id);

        return new AnalysisInput(1, 1, 5, 'คณิตศาสตร์', $entries);
    }

    public function test_areas_are_the_three_weakest_below_075_and_strengths_the_three_strongest(): void
    {
        $input = self::input([[1, 0.1, 1], [2, 0.2, 3], [3, 0.2, 2], [4, 0.74, 5], [5, 0.75, 2], [6, 0.8, 2], [7, 0.9, 2], [8, 1.0, 1]]);

        $this->assertSame([1, 2, 3], array_column($input->areas(), 'skill_id'), 'ties by skill id');
        $this->assertSame([8, 7, 6], array_column($input->strengths(), 'skill_id'));
        $this->assertSame([], self::input([[1, 0.5, 2]])->strengths());
        $this->assertSame([], self::input([[1, 0.9, 2]])->areas());
    }

    public function test_the_hash_follows_values_and_observations_only(): void
    {
        $base = self::input([[1, 0.5, 2], [2, 0.8, 3]]);

        $this->assertSame($base->hash(), self::input([[2, 0.8, 3], [1, 0.5, 2, 4]])->hash(), 'order and practice counts do not matter');
        $this->assertNotSame($base->hash(), self::input([[1, 0.5, 3], [2, 0.8, 3]])->hash());
        $this->assertNotSame($base->hash(), self::input([[1, 0.501, 2], [2, 0.8, 3]])->hash());
        $this->assertSame(64, strlen($base->hash()));
    }

    public function test_the_check_refuses_the_forbidden_word_and_empty_texts(): void
    {
        $input = self::input([[1, 0.3, 2, 1]]);

        foreach ([
            ['teacher_text' => 'ครู', 'student_text' => 'ยังอ่อนอยู่', 'next_step_skill_codes' => []],
            ['teacher_text' => ' ', 'student_text' => 'ดีมาก', 'next_step_skill_codes' => []],
            ['teacher_text' => 'ครู', 'student_text' => '', 'next_step_skill_codes' => []],
        ] as $data) {
            try {
                StudentAnalysisRequests::check($data, $input);
                $this->fail('expected invalid output');
            } catch (GeminiException $e) {
                $this->assertSame(GeminiException::INVALID_OUTPUT, $e->status);
            }
        }
    }

    public function test_next_steps_are_indicators_of_the_input_with_practice(): void
    {
        $input = self::input([[1, 0.3, 2, 2], [2, 0.4, 2, 0], [3, 0.5, 2, 1], [4, 0.6, 2, 1], [5, 0.7, 2, 1]]);

        $out = StudentAnalysisRequests::check([
            'teacher_text' => ' ครู ',
            'student_text' => str_repeat('ก', 1200),
            'next_step_skill_codes' => ['ค1.1ป.5/1', 'ค 1.1 ป.5/2', 'ค 9.9', 'ค 1.1 ป.5/1', 'ค 1.1 ป.5/3', 'ค 1.1 ป.5/4', 'ค 1.1 ป.5/5'],
        ], $input);

        $this->assertSame([1, 3, 4], $out['next_step_skill_ids'], 'normalised codes, no practice or unknown dropped, at most 3');
        $this->assertSame('ครู', $out['teacher_text']);
        $this->assertSame(StudentAnalysisRequests::MAX_STUDENT_CHARS, mb_strlen($out['student_text']));
    }

    public function test_batch_states_with_either_prefix(): void
    {
        $this->assertSame('succeeded', GeminiBatch::normaliseState('BATCH_STATE_SUCCEEDED'));
        $this->assertSame('running', GeminiBatch::normaliseState('JOB_STATE_RUNNING'));
        $this->assertSame('expired', GeminiBatch::normaliseState('EXPIRED'));
        $this->assertSame('unknown', GeminiBatch::normaliseState('BATCH_STATE_UNSPECIFIED'));
        $this->assertSame('unknown', GeminiBatch::normaliseState(null));
        $this->assertTrue((new GeminiBatch('batches/a', 'failed'))->isDone());
        $this->assertFalse((new GeminiBatch('batches/a', 'pending'))->isDone());
    }
}
