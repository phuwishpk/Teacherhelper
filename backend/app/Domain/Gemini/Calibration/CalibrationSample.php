<?php

namespace App\Domain\Gemini\Calibration;

use App\Models\Question;
use App\Models\RubricCriterion;

/**
 * One labelled question of the calibration set: an unsaved Question (its
 * position is the question number inside its unit), its rubric and what is
 * really written (label). cnn: the phone's digit reading, when known.
 */
final readonly class CalibrationSample
{
    /**
     * @param  list<RubricCriterion>  $criteria
     * @param  array<string, mixed>  $label
     * @param  array{text: string, confidence: float}|null  $cnn
     */
    public function __construct(
        public string $id,
        public Question $question,
        public array $criteria,
        public array $label,
        public ?array $cnn = null,
    ) {}
}
