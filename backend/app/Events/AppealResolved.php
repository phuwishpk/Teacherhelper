<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The teacher accepted or rejected an appeal (DESIGN §13). scoreChanged is
 * true when final_score or final_understanding changed, the cue to recompute
 * mastery (§14.2).
 */
class AppealResolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $appealId,
        public readonly int $responseId,
        public readonly int $studentId,
        public readonly bool $scoreChanged,
    ) {}
}
