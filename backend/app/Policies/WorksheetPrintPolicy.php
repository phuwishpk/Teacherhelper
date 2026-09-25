<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorksheetPrint;

/**
 * Worksheet PDFs carry student names: only the teacher who requested the
 * print, or who teaches the classroom, may poll or download it. Files have
 * no public URL (DESIGN §7.3).
 */
class WorksheetPrintPolicy
{
    public function view(User $user, WorksheetPrint $print): bool
    {
        $assignment = $print->assignment;
        if ($assignment === null || ! $user->isTeacher() || ! $user->isActive() || $assignment->school_id !== $user->school_id) {
            return false;
        }

        return $print->requested_by === $user->id || $assignment->classroom?->teacher_id === $user->id;
    }

    public function download(User $user, WorksheetPrint $print): bool
    {
        return $this->view($user, $print);
    }
}
