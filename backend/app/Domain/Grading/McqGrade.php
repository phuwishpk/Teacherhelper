<?php

namespace App\Domain\Grading;

/**
 * The result of grading one multiple-choice response (McqGrader).
 */
final readonly class McqGrade
{
    /**
     * @param  list<string>  $errorTypes
     * @param  array<string, mixed>  $trace
     */
    public function __construct(
        public float $scoreRatio,
        public float $score,
        public string $understanding,
        public array $errorTypes,
        public float $reviewPriority,
        public string $priorityBand,
        public array $trace,
    ) {}
}
