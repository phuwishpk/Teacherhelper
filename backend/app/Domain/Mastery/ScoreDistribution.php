<?php

namespace App\Domain\Mastery;

use App\Models\Assignment;
use App\Models\Submission;

/**
 * Chart (4) of DESIGN §20.4: the totals that count (COALESCE(total_override,
 * total_score), §19.3) of an assignment's published submissions, in ten
 * bins of 10 % of the full marks, with the mean and the median.
 *
 * Bins are [from, to) except the last, which holds the full marks too; a
 * total above the full marks (an override) falls in the last bin, one
 * below zero in the first.
 */
final class ScoreDistribution
{
    public const BIN_COUNT = 10;

    /**
     * @return array<string, mixed>
     */
    public static function forAssignment(Assignment $assignment): array
    {
        $max = round((float) $assignment->questions()->sum('max_points'), 2);
        $submissions = Submission::query()
            ->where('assignment_id', $assignment->id)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->get(['id', 'total_score', 'total_override', 'status']);
        $totals = $submissions->map(fn (Submission $s) => $s->effectiveTotal())->filter(fn (?float $t) => $t !== null)->values()->all();
        sort($totals);

        $counts = array_fill(0, self::BIN_COUNT, 0);
        if ($max > 0) {
            foreach ($totals as $total) {
                $bin = (int) floor($total / $max * self::BIN_COUNT + 1e-9);
                $counts[max(0, min(self::BIN_COUNT - 1, $bin))]++;
            }
        }

        $n = count($totals);
        $mean = $n === 0 ? null : round(array_sum($totals) / $n, 2);
        $median = $n === 0 ? null : round($n % 2 === 1 ? $totals[intdiv($n, 2)] : ($totals[$n / 2 - 1] + $totals[$n / 2]) / 2, 2);
        $ratio = fn (?float $v) => $v === null || $max <= 0 ? null : MasteryCalculator::round3($v / $max);

        return [
            'assignment_id' => $assignment->id,
            'max_points' => $max,
            'published_count' => $submissions->count(),
            'scored_count' => $n,
            'mean' => $mean,
            'median' => $median,
            'mean_ratio' => $ratio($mean),
            'median_ratio' => $ratio($median),
            'bins' => $max <= 0 ? [] : array_map(fn (int $i) => [
                'from_ratio' => round($i / self::BIN_COUNT, 1),
                'to_ratio' => round(($i + 1) / self::BIN_COUNT, 1),
                'from_points' => round($max * $i / self::BIN_COUNT, 2),
                'to_points' => round($max * ($i + 1) / self::BIN_COUNT, 2),
                'count' => $counts[$i],
            ], range(0, self::BIN_COUNT - 1)),
        ];
    }
}
