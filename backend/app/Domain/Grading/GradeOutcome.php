<?php

namespace App\Domain\Grading;

/**
 * What GradeScanJob writes for one graded answer: fuzzy system 1 (score,
 * understanding), system 2 (priority) and the trace behind both. state is
 * `scored`, or `manual` when fuzzy could not decide (manualReason set).
 */
final readonly class GradeOutcome
{
    /**
     * @param  list<string>  $errorTypes
     * @param  array<string, mixed>  $trace  responses.fuzzy_trace
     */
    public function __construct(
        public string $state,
        public ?float $scoreRatio,
        public ?float $score,
        public ?string $understanding,
        public array $errorTypes,
        public float $reviewPriority,
        public string $priorityBand,
        public array $trace,
        public bool $blank = false,
        public bool $suspicious = false,
        public ?string $manualReason = null,
    ) {}

    public function isScored(): bool
    {
        return $this->state === GradeResult::SCORED;
    }
}
