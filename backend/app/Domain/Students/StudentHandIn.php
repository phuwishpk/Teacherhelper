<?php

namespace App\Domain\Students;

use App\Models\Assignment;

/**
 * When a student may hand in an assignment in the app (DESIGN §19.9,
 * §24.6): after the due time it is late, refused when the assignment does
 * not accept late work, and a closed classroom ("ห้องเก่า") takes nothing.
 */
final class StudentHandIn
{
    public static function isLate(Assignment $assignment): bool
    {
        return $assignment->due_at !== null && now()->greaterThan($assignment->due_at);
    }

    public static function refusesLate(Assignment $assignment): bool
    {
        return ! $assignment->accept_late && self::isLate($assignment);
    }

    /** The classroom relation is loaded (or loads) to see whether it is closed. */
    public static function canSubmit(Assignment $assignment): bool
    {
        return ! self::refusesLate($assignment) && $assignment->classroom?->isClosed() !== true;
    }
}
