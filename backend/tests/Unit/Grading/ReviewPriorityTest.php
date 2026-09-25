<?php

namespace Tests\Unit\Grading;

use App\Domain\Grading\ReviewPriority;
use App\Domain\Grading\ScoreRounding;
use App\Domain\Grading\Understanding;
use PHPUnit\Framework\TestCase;

/** DESIGN §11.7 (levels, rounding) and §11.8 (fuzzy system 2). */
class ReviewPriorityTest extends TestCase
{
    public function test_the_worked_example_of_the_design(): void
    {
        // D = 0, L = 0.4, B = 0 -> P2 w 0.4, P4 w 0.6 -> p = 0.32 "look".
        $result = ReviewPriority::evaluate(0.0, 0.4, 0.0);

        $this->assertEqualsWithDelta(0.32, $result['p'], 1e-9);
        $this->assertSame('look', $result['band']);
        $this->assertSame(['P1' => 0.0, 'P2' => 0.4, 'P3' => 0.0, 'P4' => 0.6], array_column($result['trace']['rules'], 'w', 'rule'));
    }

    public function test_bands_and_extremes(): void
    {
        $this->assertSame(['p' => 0.0, 'band' => 'confident'], array_intersect_key(ReviewPriority::evaluate(0, 0, 0), ['p' => 1, 'band' => 1]));
        $this->assertSame(1.0, ReviewPriority::evaluate(1, 0, 0)['p']);
        $this->assertSame('check', ReviewPriority::band(0.5));
        $this->assertSame('look', ReviewPriority::band(0.2));
        $this->assertSame('confident', ReviewPriority::band(0.1999));
        $this->assertSame(1.0, ReviewPriority::evaluate(0, 0, 0, suspicious: true)['p']);
    }

    public function test_boundary_closeness(): void
    {
        $this->assertSame(1.0, ReviewPriority::boundaryCloseness(0.4));
        $this->assertSame(1.0, ReviewPriority::boundaryCloseness(0.75));
        $this->assertEqualsWithDelta(0.5, ReviewPriority::boundaryCloseness(0.8), 1e-9);
        $this->assertSame(0.0, ReviewPriority::boundaryCloseness(1.0));
        $this->assertSame(0.0, ReviewPriority::boundaryCloseness(0.0));
    }

    public function test_understanding_levels(): void
    {
        $this->assertSame('good', Understanding::fromU(0.75));
        $this->assertSame('partial', Understanding::fromU(0.74));
        $this->assertSame('partial', Understanding::fromU(0.4));
        $this->assertSame('not_yet', Understanding::fromU(0.39));
    }

    public function test_score_rounding_to_half_points(): void
    {
        $this->assertSame(2.5, ScoreRounding::score(0.5, 5));
        $this->assertSame(3.5, ScoreRounding::score(0.7, 5));
        $this->assertSame(1.25, ScoreRounding::score(1.0, 1.25)); // full marks stay exact
        $this->assertSame(1.0, ScoreRounding::score(0.9, 1.25)); // 1.125 -> 1.0, never above max
        $this->assertSame(0.0, ScoreRounding::score(-1, 5));
        $this->assertSame(4.75, ScoreRounding::score(0.95, 5, 0.25));
    }
}
