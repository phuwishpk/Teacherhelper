<?php

namespace App\Domain\Gradebook;

/**
 * What is stored for one student on one column (DESIGN §23.4): the typed
 * score of a manual exam or item, or the published total
 * (effectiveTotal()) of an app-graded submission, and "ยกเว้น".
 */
final readonly class GradebookCell
{
    public function __construct(
        public ?float $score = null,
        public bool $excused = false,
        public bool $hasSubmission = false,
        public bool $published = false,
        public ?int $submissionId = null,
    ) {}
}
