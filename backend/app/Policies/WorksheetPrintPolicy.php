<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorksheetPrint;

/**
 * Worksheet PDFs carry student names: only the teacher who currently teaches
 * the classroom may poll or download a print, with the same rule as every
 * other assignment action (AssignmentPolicy::owns, DESIGN §9). Who requested
 * the print does not matter, so a teacher loses access when the classroom
 * moves to someone else. Files have no public URL (DESIGN §7.3).
 */
class WorksheetPrintPolicy
{
    public function view(User $user, WorksheetPrint $print): bool
    {
        $assignment = $print->assignment;

        return $assignment !== null && AssignmentPolicy::owns($user, $assignment);
    }

    public function download(User $user, WorksheetPrint $print): bool
    {
        return $this->view($user, $print);
    }
}
