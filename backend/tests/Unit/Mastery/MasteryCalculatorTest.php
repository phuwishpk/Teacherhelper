<?php

namespace Tests\Unit\Mastery;

use App\Domain\Mastery\MasteryCalculator;
use PHPUnit\Framework\TestCase;

/**
 * DESIGN §14.2: m1 = s1, mt = α·st + (1 − α)·mt−1 with α = 0.30 for
 * homework and 0.15 for practice, in observed_at order.
 */
class MasteryCalculatorTest extends TestCase
{
    public function test_the_worked_example_of_the_design(): void
    {
        // homework 0.5 -> m1 = 0.5
        // practice 1.0 -> m2 = 0.15·1 + 0.85·0.5 = 0.575
        // homework 1.0 -> m3 = 0.30·1 + 0.70·0.575 = 0.7025 -> 0.703 (3 decimals)
        $result = MasteryCalculator::ewma([
            ['score_ratio' => 0.5, 'source' => 'homework'],
            ['score_ratio' => 1.0, 'source' => 'practice'],
            ['score_ratio' => 1.0, 'source' => 'homework'],
        ]);

        $this->assertSame(['value' => 0.703, 'n_obs' => 3], $result);
    }

    public function test_the_first_observation_is_taken_as_is_and_order_matters(): void
    {
        $this->assertSame(['value' => 0.25, 'n_obs' => 1], MasteryCalculator::ewma([['score_ratio' => 0.25, 'source' => 'practice']]));
        $this->assertNull(MasteryCalculator::ewma([]));

        $up = MasteryCalculator::ewma([['score_ratio' => 0, 'source' => 'homework'], ['score_ratio' => 1, 'source' => 'homework']]);
        $down = MasteryCalculator::ewma([['score_ratio' => 1, 'source' => 'homework'], ['score_ratio' => 0, 'source' => 'homework']]);
        $this->assertSame(0.3, $up['value']);
        $this->assertSame(0.7, $down['value']);
    }

    public function test_rounding_is_half_up_on_every_php_version(): void
    {
        $this->assertSame(0.703, MasteryCalculator::round3(0.7025));
        $this->assertSame(0.702, MasteryCalculator::round3(0.7024));
        $this->assertSame(1.0, MasteryCalculator::round3(0.9999));
        $this->assertSame(0.0, MasteryCalculator::round3(0.0));
    }

    public function test_ratios_are_clamped_and_practice_weighs_less_than_homework(): void
    {
        $this->assertSame(1.0, MasteryCalculator::ewma([['score_ratio' => '1.7', 'source' => 'homework']])['value']);
        $this->assertSame(0.0, MasteryCalculator::ewma([['score_ratio' => -2, 'source' => 'homework']])['value']);

        $homework = MasteryCalculator::ewma([['score_ratio' => 0, 'source' => 'homework'], ['score_ratio' => 1, 'source' => 'homework']]);
        $practice = MasteryCalculator::ewma([['score_ratio' => 0, 'source' => 'homework'], ['score_ratio' => 1, 'source' => 'practice']]);
        $this->assertGreaterThan($practice['value'], $homework['value']);
        $this->assertSame(0.15, $practice['value']);
    }

    public function test_levels_follow_the_understanding_cut_points_with_a_minimum_of_two_observations(): void
    {
        $this->assertSame('too_little', MasteryCalculator::level(0.9, 1));
        $this->assertSame('good', MasteryCalculator::level(0.75, 2));
        $this->assertSame('partial', MasteryCalculator::level(0.4, 2));
        $this->assertSame('partial', MasteryCalculator::level(0.749, 5));
        $this->assertSame('not_yet', MasteryCalculator::level(0.399, 2));
    }
}
