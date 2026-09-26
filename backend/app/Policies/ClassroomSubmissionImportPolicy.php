<?php

namespace App\Policies;

use App\Models\ClassroomSubmissionImport;
use App\Models\User;

/**
 * A Google Classroom submission row (DESIGN §18.6): only the teacher of the
 * assignment's classroom may act on it (return it for a retake).
 */
class ClassroomSubmissionImportPolicy
{
    /** GET /student/retake-requests: a student lists only their own rows (the query is scoped). */
    public function viewOwn(User $user): bool
    {
        return $user->isStudent() && $user->isActive();
    }

    public function update(User $user, ClassroomSubmissionImport $import): bool
    {
        $assignment = $import->assignment;

        return $assignment !== null && AssignmentPolicy::owns($user, $assignment);
    }
}
