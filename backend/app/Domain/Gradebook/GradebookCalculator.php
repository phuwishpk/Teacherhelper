<?php

namespace App\Domain\Gradebook;

use Carbon\CarbonInterface;

/**
 * The gradebook formula of DESIGN §23.4, by code only (no AI), with values
 * rounded to 4 decimals at each step:
 *
 *   cell pct     100 × score / full marks, clamped to 0–100; see cell()
 *                for the states (excused, pending, not due, missing = 0)
 *   category     mean of the student's pcts after dropping the
 *                min(drop_lowest, n − 1) lowest; points = weight × mean / 100
 *   total        Σ points × 100 / Σ weight over the categories the student
 *                has a value in (= Σ points when all of them)
 *   complete     every category has a counted column in the classroom;
 *                only then rounded = round-half-up(total) and a grade
 *   attendance   Σ score / Σ full of the is_attendance items with a score
 *                in the classroom (blank = 0, excused left out) < 0.80
 *                → "อาจติด มส" (a warning only)
 *
 * ร/มส replace the numeric grade (grade = null, special set).
 */
final class GradebookCalculator
{
    public const ATTENDANCE_MIN = 0.80;

    public const SCORED = 'scored';

    public const MISSING = 'missing';

    public const NOT_DUE = 'not_due';

    public const PENDING = 'pending';

    public const NOT_COUNTED = 'not_counted';

    public const EXCUSED = 'excused';

    /**
     * @param  list<GradebookCategorySpec>  $categories  in order
     * @param  list<int>  $cutoffs
     */
    public function __construct(
        private readonly array $categories,
        private readonly array $cutoffs,
        private readonly CarbonInterface $now,
    ) {}

    /**
     * @param  list<GradebookColumn>  $columns
     * @param  list<int>  $studentIds
     * @param  array<int, array<string, GradebookCell>>  $cells  student id => column key => cell; absent = empty
     * @param  array<int, string>  $specials  student id => r|ms
     * @return array{complete: bool, missing_categories: list<int>, counted_weight: float, counted: array<string, bool>, rows: array<int, array<string, mixed>>}
     */
    public function compute(array $columns, array $studentIds, array $cells, array $specials = []): array
    {
        $counted = [];
        $categoryIds = array_map(fn (GradebookCategorySpec $c) => $c->id, $this->categories);
        $hasCounted = array_fill_keys($categoryIds, false);
        foreach ($columns as $column) {
            $isCounted = $column->counted($this->now) && array_key_exists((int) $column->categoryId, $hasCounted);
            $counted[$column->key] = $isCounted;
            if ($isCounted) {
                $hasCounted[(int) $column->categoryId] = true;
            }
        }
        $missing = array_values(array_keys(array_filter($hasCounted, fn (bool $has) => ! $has)));
        $complete = $this->categories !== [] && $missing === [];
        $countedWeight = 0.0;
        foreach ($this->categories as $category) {
            if ($hasCounted[$category->id]) {
                $countedWeight += $category->weight;
            }
        }

        $rows = [];
        foreach ($studentIds as $studentId) {
            $rows[$studentId] = $this->row($columns, $counted, $cells[$studentId] ?? [], $complete, $specials[$studentId] ?? null);
        }

        return [
            'complete' => $complete,
            'missing_categories' => $missing,
            'counted_weight' => self::round4($countedWeight),
            'counted' => $counted,
            'rows' => $rows,
        ];
    }

    public static function round4(float $value): float
    {
        return round($value, 4);
    }

    /** Round half up to a whole number, after rounding to 4 decimals (§23.4, so 79.49999… from float noise is 79.5 → 80). */
    public static function roundTotal(float $total): int
    {
        return (int) round(round($total, 4), 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @param  list<GradebookColumn>  $columns
     * @param  array<string, bool>  $counted
     * @param  array<string, GradebookCell>  $cells
     * @return array<string, mixed>
     */
    private function row(array $columns, array $counted, array $cells, bool $complete, ?string $special): array
    {
        $outCells = [];
        /** @var array<int, list<array{key: string, pct: float, order: int}>> $pcts */
        $pcts = [];
        $attendanceScore = 0.0;
        $attendanceMax = 0.0;
        foreach ($columns as $order => $column) {
            $cell = $cells[$column->key] ?? new GradebookCell;
            $out = $this->cell($column, $cell, $counted[$column->key]);
            $outCells[$column->key] = $out;
            if ($counted[$column->key] && in_array($out['state'], [self::SCORED, self::MISSING], true)) {
                $pcts[(int) $column->categoryId][] = ['key' => $column->key, 'pct' => (float) $out['percent'], 'order' => $order];
            }
            if ($column->type === GradebookColumn::CUSTOM && $column->isAttendance && $column->anyScored && ! $cell->excused && $column->fullMarks > 0) {
                $attendanceScore += (float) ($cell->score ?? 0.0);
                $attendanceMax += $column->fullMarks;
            }
        }

        $categories = [];
        $pointsSum = 0.0;
        $weightSum = 0.0;
        foreach ($this->categories as $category) {
            $values = $pcts[$category->id] ?? [];
            if ($values === []) {
                $categories[$category->id] = ['percent' => null, 'points' => null];

                continue;
            }
            usort($values, fn (array $a, array $b) => [$a['pct'], $a['order']] <=> [$b['pct'], $b['order']]);
            $drop = min($category->dropLowest, count($values) - 1);
            foreach (array_slice($values, 0, $drop) as $dropped) {
                $outCells[$dropped['key']]['dropped'] = true;
            }
            $kept = array_slice($values, $drop);
            $percent = self::round4(array_sum(array_column($kept, 'pct')) / count($kept));
            $points = self::round4($category->weight * $percent / 100);
            $categories[$category->id] = ['percent' => $percent, 'points' => $points];
            $pointsSum += $points;
            $weightSum += $category->weight;
        }

        $total = $weightSum > 0 ? self::round4($pointsSum * 100 / $weightSum) : null;
        $rounded = $complete && $total !== null ? self::roundTotal($total) : null;
        $grade = $rounded !== null && $special === null ? GradeCutoffs::grade($rounded, $this->cutoffs) : null;

        return [
            'cells' => $outCells,
            'categories' => $categories,
            'total' => $total,
            'total_rounded' => $rounded,
            'grade' => $grade,
            'special' => $special,
            'attendance_warning' => $attendanceMax > 0 && self::round4($attendanceScore / $attendanceMax) < self::ATTENDANCE_MIN,
            'in_progress' => ! $complete,
            'counted_weight' => self::round4($weightSum),
        ];
    }

    /**
     * The state of one cell, in the order of §23.4 (the first that matches).
     *
     * @return array{score: float|null, percent: float|null, state: string, dropped: bool, submission_id?: int|null}
     */
    private function cell(GradebookColumn $column, GradebookCell $cell, bool $counted): array
    {
        $score = null;
        $state = self::NOT_DUE;
        if ($column->type === GradebookColumn::ASSIGNMENT) {
            if ($cell->published) {
                $score = $cell->score ?? 0.0;
                $state = self::SCORED;
            } elseif ($cell->hasSubmission) {
                $state = self::PENDING;
            } elseif ($column->overdue($this->now)) {
                $state = self::MISSING;
            }
        } elseif ($cell->score !== null) {
            $score = $cell->score;
            $state = self::SCORED;
        } elseif ($column->type === GradebookColumn::CUSTOM || $column->overdue($this->now)) {
            $state = self::MISSING;
        }

        $percent = match (true) {
            $state === self::SCORED && $column->fullMarks > 0 => self::round4(max(0.0, min(100.0, 100 * $score / $column->fullMarks))),
            $state === self::MISSING => 0.0,
            default => null,
        };
        if ($cell->excused) {
            $state = self::EXCUSED;
            $percent = null;
        } elseif (! $counted) {
            $state = self::NOT_COUNTED;
            if ($score === null) {
                $percent = null;
            }
        }

        $out = ['score' => $score === null ? null : round($score, 2), 'percent' => $percent, 'state' => $state, 'dropped' => false];
        if ($column->type === GradebookColumn::ASSIGNMENT) {
            $out['submission_id'] = $cell->submissionId;
        }

        return $out;
    }
}
