<?php

namespace App\Domain\Grading;

/** What one GradeScanJob run did. retry = some answer failed and has attempts left. */
final readonly class ScanGradingResult
{
    public function __construct(
        public int $scored = 0,
        public int $failed = 0,
        public int $manual = 0,
        public int $skipped = 0,
        public bool $retry = false,
    ) {}
}
