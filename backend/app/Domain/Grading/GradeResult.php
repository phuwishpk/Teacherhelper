<?php

namespace App\Domain\Grading;

/**
 * Output of fuzzy system 1 for one answer (FuzzyGrader). `manual` when no
 * rule fired (Σw = 0, DESIGN §11.1); then there is no score. trace is null
 * for a blank answer, which skips fuzzy (score 0, u 0).
 */
final readonly class GradeResult
{
    public const SCORED = 'scored';

    public const MANUAL = 'manual';

    /**
     * @param  array<string, float>  $inputs  crisp inputs of the rule set (F/S, M, K/R)
     */
    public function __construct(
        public string $system,
        public string $strictness,
        public string $state,
        public ?float $scoreRatio,
        public ?float $u,
        public ?string $understanding,
        public array $inputs,
        public ?FuzzyResult $trace,
    ) {}

    public function isScored(): bool
    {
        return $this->state === self::SCORED;
    }

    public function score(float $maxPoints, float $step = ScoreRounding::DEFAULT_STEP): ?float
    {
        return $this->scoreRatio === null ? null : ScoreRounding::score($this->scoreRatio, $maxPoints, $step);
    }
}
