<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A student asked the teacher to re-check one published answer (DESIGN §13). */
class AppealOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $appealId,
        public readonly int $teacherId,
    ) {}
}
