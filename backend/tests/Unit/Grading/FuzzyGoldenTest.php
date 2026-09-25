<?php

namespace Tests\Unit\Grading;

use App\Domain\Grading\FuzzyEngine;
use App\Domain\Grading\FuzzyGrader;
use App\Domain\Grading\Membership;
use App\Domain\Grading\ReviewPriority;
use App\Domain\Grading\RuleSets;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DESIGN §11: the worked examples as golden tests (§11.3, §11.8, §11.9),
 * every rule alone at its corner and the engine's own contract.
 */
class FuzzyGoldenTest extends TestCase
{
    public function test_golden_show_work_example_of_design_11_3(): void
    {
        // 3 of 4 steps valid, final answer wrong, 5 points, normal strictness.
        $result = FuzzyGrader::showWork(0.0, 0.75, 'normal');

        $this->assertTrue($result->isScored());
        $this->assertEqualsWithDelta(0.538, $result->scoreRatio, 0.001);
        $this->assertEqualsWithDelta(0.525, $result->u, 0.001);
        $this->assertSame('partial', $result->understanding);
        $this->assertSame(2.5, $result->score(5));

        $trace = $result->trace;
        $this->assertEqualsWithDelta(['wrong' => 1.0, 'correct' => 0.0], $trace->memberships['F'], 1e-9);
        $this->assertEqualsWithDelta(['few' => 0.0, 'medium' => 0.167, 'many' => 0.5], $trace->memberships['S'], 0.001);
        $weights = $trace->weights();
        $this->assertEqualsWithDelta(0.5, $weights['R4'], 1e-9);
        $this->assertEqualsWithDelta(0.167, $weights['R5'], 0.001);
        foreach (['R1', 'R2', 'R3', 'R6'] as $rule) {
            $this->assertSame(0.0, $weights[$rule]);
        }
        $this->assertSame(['R4', 'R5'], $trace->fired());
        $this->assertEqualsWithDelta(0.667, $trace->weightSum, 0.001);
    }

    public function test_golden_review_priority_example_of_design_11_8(): void
    {
        // D = 0, L = 0.4 (readable), B = 0: P2 w 0.4, P4 w 0.6 -> p = 0.32 "look".
        $result = ReviewPriority::evaluate(0.0, 0.4, 0.0);

        $this->assertEqualsWithDelta(0.32, $result->p, 1e-9);
        $this->assertSame('look', $result->band);
        $this->assertNull($result->flag);
        $this->assertFalse($result->bulkApprovable());
        $this->assertEqualsWithDelta(['P1' => 0.0, 'P2' => 0.4, 'P3' => 0.0, 'P4' => 0.6], $result->trace->weights(), 1e-9);
    }

    /**
     * Crisp corners: exactly one rule fires with weight 1, so the output is its singleton.
     *
     * @return array<string, array{0: string, 1: array<string, float>, 2: string, 3: list<float>, 4: float}>
     */
    public static function corners(): array
    {
        return [
            'R1' => [RuleSets::SHOW_WORK, ['F' => 1.0, 'S' => 1.0], 'R1', [1.00, 1.00, 1.00], 1.0],
            'R2' => [RuleSets::SHOW_WORK, ['F' => 1.0, 'S' => 0.5], 'R2', [0.85, 0.80, 0.70], 0.7],
            'R3' => [RuleSets::SHOW_WORK, ['F' => 1.0, 'S' => 0.0], 'R3', [0.60, 0.50, 0.30], 0.4],
            'R4' => [RuleSets::SHOW_WORK, ['F' => 0.0, 'S' => 1.0], 'R4', [0.70, 0.60, 0.40], 0.6],
            'R5' => [RuleSets::SHOW_WORK, ['F' => 0.0, 'S' => 0.5], 'R5', [0.45, 0.35, 0.20], 0.3],
            'R6' => [RuleSets::SHOW_WORK, ['F' => 0.0, 'S' => 0.0], 'R6', [0.00, 0.00, 0.00], 0.0],
            'S1' => [RuleSets::SHORT, ['M' => 0.0], 'S1', [0.0, 0.0, 0.0], 0.0],
            'S2' => [RuleSets::SHORT, ['M' => 0.7], 'S2', [0.7, 0.5, 0.0], 0.5],
            'S3' => [RuleSets::SHORT, ['M' => 1.0], 'S3', [1.0, 1.0, 1.0], 1.0],
            'O1' => [RuleSets::OPEN, ['K' => 1.0, 'R' => 1.0], 'O1', [1.00, 1.00, 1.00], 1.0],
            'O2' => [RuleSets::OPEN, ['K' => 1.0, 'R' => 0.5], 'O2', [0.85, 0.80, 0.70], 0.75],
            'O3' => [RuleSets::OPEN, ['K' => 1.0, 'R' => 0.0], 'O3', [0.70, 0.60, 0.50], 0.55],
            'O4' => [RuleSets::OPEN, ['K' => 0.0, 'R' => 1.0], 'O4', [0.55, 0.45, 0.30], 0.35],
            'O5' => [RuleSets::OPEN, ['K' => 0.0, 'R' => 0.5], 'O5', [0.35, 0.25, 0.10], 0.2],
            'O6' => [RuleSets::OPEN, ['K' => 0.0, 'R' => 0.0], 'O6', [0.00, 0.00, 0.00], 0.0],
        ];
    }

    /**
     * @param  array<string, float>  $inputs
     * @param  list<float>  $zByStrictness
     */
    #[DataProvider('corners')]
    public function test_each_rule_alone_at_its_corner(string $set, array $inputs, string $rule, array $zByStrictness, float $u): void
    {
        foreach (RuleSets::STRICTNESS as $i => $strictness) {
            $result = RuleSets::engine($set, $strictness)->evaluate($inputs);

            $this->assertSame([$rule], $result->fired(), "{$rule} {$strictness}");
            $this->assertEqualsWithDelta(1.0, $result->weights()[$rule], 1e-12);
            $this->assertEqualsWithDelta($zByStrictness[$i], $result->output('score_ratio'), 1e-12);
            $this->assertEqualsWithDelta($u, $result->output('u'), 1e-12);
        }
    }

    public function test_priority_rules_each_alone(): void
    {
        $this->assertSame(1.0, ReviewPriority::evaluate(1, 0, 0)->p);
        $this->assertEqualsWithDelta(0.8, ReviewPriority::evaluate(0, 1, 0)->p, 1e-12);
        $this->assertEqualsWithDelta(0.6, ReviewPriority::evaluate(0, 0, 1)->p, 1e-12);
        $this->assertSame(0.0, ReviewPriority::evaluate(0, 0, 0)->p);
        $this->assertTrue(ReviewPriority::evaluate(0, 0, 0)->bulkApprovable());
    }

    public function test_suspicious_instruction_and_manual_skip_fuzzy(): void
    {
        $suspicious = ReviewPriority::evaluate(0, 0, 0, suspicious: true);
        $this->assertSame([1.0, 'check', 'suspicious'], [$suspicious->p, $suspicious->band, $suspicious->flag]);
        $this->assertNull($suspicious->trace);
        $this->assertFalse($suspicious->bulkApprovable());

        $manual = ReviewPriority::manual();
        $this->assertSame([1.0, 'check', 'manual'], [$manual->p, $manual->band, $manual->flag]);
    }

    public function test_the_trace_has_the_ml_fuzzy_shape(): void
    {
        $trace = FuzzyGrader::short(0.9, 'normal')->trace->toArray();

        $this->assertSame(['inputs', 'memberships', 'rules', 'weight_sum', 'outputs', 'degenerate'], array_keys($trace));
        $this->assertSame(['name', 'weight', 'then', 'note'], array_keys($trace['rules'][0]));
        $this->assertFalse($trace['degenerate']);
        $this->assertSame(0.9, $trace['inputs']['M']);
    }

    public function test_membership_functions(): void
    {
        $this->assertSame(0.0, Membership::rampUp(0.5, 0.5, 1.0));
        $this->assertSame(1.0, Membership::rampUp(1.2, 0.5, 1.0));
        $this->assertSame(1.0, Membership::rampDown(-1, 0.0, 0.5));
        $this->assertEqualsWithDelta(0.5, Membership::tri(0.35, 0.2, 0.5, 0.8), 1e-12);
        $this->assertSame(1.0, Membership::tri(0.5, 0.2, 0.5, 0.8));
        $this->assertSame(0.0, Membership::tri(0.8, 0.2, 0.5, 0.8));
        $this->assertEqualsWithDelta(0.25, Membership::low(0.75), 1e-12);
        $this->assertSame(0.75, Membership::high(0.75));
    }

    public function test_a_degenerate_rule_set_is_reported_not_divided_by_zero(): void
    {
        $engine = new FuzzyEngine(
            ['X' => ['mid' => ['type' => 'tri', 'a' => 0.4, 'm' => 0.5, 'b' => 0.6]]],
            [['name' => 'only', 'when' => ['X' => 'mid'], 'then' => ['y' => 1.0]]],
            ['y'],
        );

        $result = $engine->evaluate(['X' => 0.0]);

        $this->assertTrue($result->isDegenerate());
        $this->assertSame([], $result->outputs);
        $this->assertNull($result->output('y'));
    }

    public function test_inputs_must_be_ratios(): void
    {
        $engine = RuleSets::engine(RuleSets::SHORT);
        $this->assertSame(1.0, $engine->evaluate(['M' => 1.0 + 1e-12])->inputs['M'], 'float noise is clamped');

        $this->expectException(InvalidArgumentException::class);
        $engine->evaluate(['M' => 1.5]);
    }

    public function test_the_engine_rejects_a_broken_rule_table(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FuzzyEngine(
            ['X' => ['low' => ['type' => 'low']]],
            [['name' => 'r', 'when' => ['X' => 'high'], 'then' => ['y' => 1.0]]],
            ['y'],
        );
    }

    public function test_unknown_strictness_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RuleSets::engine(RuleSets::SHOW_WORK, 'harsh');
    }

    public function test_lenient_ge_normal_ge_strict_and_u_ignores_strictness(): void
    {
        $grid = [0.0, 0.1, 0.25, 0.4, 0.5, 0.6, 0.75, 0.9, 1.0];
        foreach ($grid as $a) {
            foreach ($grid as $b) {
                $lenient = FuzzyGrader::showWork($a, $b, 'lenient');
                $normal = FuzzyGrader::showWork($a, $b, 'normal');
                $strict = FuzzyGrader::showWork($a, $b, 'strict');
                $this->assertGreaterThanOrEqual($normal->scoreRatio - 1e-9, $lenient->scoreRatio);
                $this->assertGreaterThanOrEqual($strict->scoreRatio - 1e-9, $normal->scoreRatio);
                $this->assertEqualsWithDelta($lenient->u, $strict->u, 1e-12);

                $this->assertGreaterThanOrEqual(
                    FuzzyGrader::open($a, $b, 'strict')->scoreRatio - 1e-9,
                    FuzzyGrader::open($a, $b, 'lenient')->scoreRatio,
                );
            }
        }
    }

    public function test_blank_skips_fuzzy(): void
    {
        $blank = FuzzyGrader::blank('short');

        $this->assertSame([0.0, 0.0, 'not_yet'], [$blank->scoreRatio, $blank->u, $blank->understanding]);
        $this->assertNull($blank->trace);
        $this->assertSame(0.0, $blank->score(2));
    }

    public function test_step_ratio_and_open_inputs(): void
    {
        $this->assertSame(0.0, FuzzyGrader::stepRatio([]));
        $this->assertSame(0.75, FuzzyGrader::stepRatio([['valid' => true], ['valid' => true], ['valid' => false], ['valid' => true]]));

        [$k, $r] = FuzzyGrader::openInputs([
            ['level' => 'met', 'points' => 2, 'is_core' => true],
            ['level' => 'partially_met', 'points' => 1, 'is_core' => false],
            ['level' => 'not_met', 'points' => 1, 'is_core' => false],
        ]);
        $this->assertSame([1.0, 0.25], [$k, $r]);

        $this->expectException(InvalidArgumentException::class);
        FuzzyGrader::openInputs([
            ['level' => 'met', 'points' => 1, 'is_core' => true],
            ['level' => 'met', 'points' => 1, 'is_core' => true],
        ]);
    }
}
