<?php

namespace App\Domain\Gradebook;

/** A category as the formula needs it (DESIGN §23.2, §23.4). */
final readonly class GradebookCategorySpec
{
    public function __construct(
        public int $id,
        public string $name,
        public float $weight,
        public int $dropLowest = 0,
    ) {}
}
