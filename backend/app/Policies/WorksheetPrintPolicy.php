<?php

namespace App\Policies;

use App\Domain\Classrooms\ClassroomAccess;
use App\Models\User;
use App\Models\WorksheetPrint;
use Illuminate\Auth\Access\Response;

/**
 * Worksheet PDFs carry student names: only the manager of the assignment
 * (DESIGN §24.8) may poll or download a print, with the same rule as every
 * other assignment action; the homeroom teacher of a subject teacher's work
 * gets 403 not_course_teacher. Who requested the print does not matter.
 * Files have no public URL (DESIGN §7.3).
 */
class WorksheetPrintPolicy
{
    public function view(User $user, WorksheetPrint $print): Response|bool
    {
        $assignment = $print->assignment;

        return $assignment === null ? false : ClassroomAccess::manageResponse($user, $assignment);
    }

    public function download(User $user, WorksheetPrint $print): Response|bool
    {
        return $this->view($user, $print);
    }
}
