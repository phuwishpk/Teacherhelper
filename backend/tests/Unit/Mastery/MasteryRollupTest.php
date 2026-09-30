<?php

namespace Tests\Unit\Mastery;

use App\Domain\Mastery\MasteryRollup;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases of the roll-up formula (DESIGN §20.3, §20.10): a plain
 * mean over the assessed indicators only, coverage = assessed / planned,
 * an empty node = null; a classroom is the plain mean of its students'
 * node values.
 */
class MasteryRollupTest extends TestCase
{
    public function test_a_student_node_is_the_plain_mean_of_the_assessed_indicators(): void
    {
        $mastery = [
            1 => ['value' => 0.8, 'n_obs' => 2],
            2 => ['value' => 0.4, 'n_obs' => 1],
            5 => ['value' => 1.0, 'n_obs' => 4], // not planned under the node: ignored
        ];

        // I = {1, 2, 3, 4}: 3 and 4 not assessed yet, so they do not pull the value down.
        $this->assertSame(
            ['value' => 0.6, 'assessed' => 2, 'planned' => 4, 'coverage' => 0.5, 'passed' => 1],
            MasteryRollup::student([1, 2, 3, 4, 2], $mastery, 0.5),
            'duplicates in I count once; 0.4 < 0.5 does not pass',
        );
        // No observation (n_obs 0) is not assessed.
        $this->assertSame(
            ['value' => null, 'assessed' => 0, 'planned' => 1, 'coverage' => 0.0, 'passed' => 0],
            MasteryRollup::student([7], [7 => ['value' => 0.9, 'n_obs' => 0]], 0.5),
        );
        // An empty node: nothing planned.
        $this->assertSame(
            ['value' => null, 'assessed' => 0, 'planned' => 0, 'coverage' => null, 'passed' => 0],
            MasteryRollup::student([], $mastery, 0.5),
        );
        // Not weighted by n_obs, rounded to 3 decimals: (0.1 + 0.2 + 0.2) / 3 = 0.1666… -> 0.167
        $this->assertSame(0.167, MasteryRollup::student([1, 2, 3], [
            1 => ['value' => 0.1, 'n_obs' => 1],
            2 => ['value' => 0.2, 'n_obs' => 9],
            3 => ['value' => 0.2, 'n_obs' => 2],
        ], 0.5)['value']);
        // The pass mark is inclusive.
        $this->assertSame(1, MasteryRollup::student([1], [1 => ['value' => 0.5, 'n_obs' => 1]], 0.5)['passed']);
        $this->assertTrue(MasteryRollup::passes(0.5, 0.5));
        $this->assertFalse(MasteryRollup::passes(0.499, 0.5));
    }

    public function test_a_classroom_node_is_the_mean_of_its_students_values(): void
    {
        $byStudent = [
            10 => [1 => ['value' => 0.8, 'n_obs' => 2], 2 => ['value' => 0.4, 'n_obs' => 1]], // 0.6
            11 => [3 => ['value' => 0.9, 'n_obs' => 1]],                                       // 0.9
            12 => [],                                                                          // not assessed
        ];

        $this->assertSame(
            ['value' => 0.75, 'assessed' => 3, 'planned' => 4, 'coverage' => 0.75, 'students_assessed' => 2, 'student_count' => 3],
            MasteryRollup::classroom([1, 2, 3, 4], $byStudent, 0.5),
        );
        $this->assertSame(
            ['value' => null, 'assessed' => 0, 'planned' => 0, 'coverage' => null, 'students_assessed' => 0, 'student_count' => 3],
            MasteryRollup::classroom([], $byStudent, 0.5),
        );
        $this->assertSame(
            ['value' => null, 'assessed' => 0, 'planned' => 2, 'coverage' => 0.0, 'students_assessed' => 0, 'student_count' => 0],
            MasteryRollup::classroom([1, 2], [], 0.5),
        );
    }
}
