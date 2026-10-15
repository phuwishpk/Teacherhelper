<?php

namespace App\Policies;

use App\Domain\Classrooms\ClassroomAccess;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Page images are seen by whoever sees the assignment's results (its manager
 * and the homeroom teacher, DESIGN §24.8); only the manager confirms a
 * rescan. Students never see page images: they get their own crops through
 * ResponsePolicy::viewCrop.
 */
class ScanPolicy
{
    public function view(User $user, Scan $scan): bool
    {
        $assignment = $scan->submission?->assignment;

        return $assignment !== null && $user->isTeacher() && ClassroomAccess::seesResults($user, $assignment);
    }

    public function confirmReplace(User $user, Scan $scan): Response|bool
    {
        $assignment = $scan->submission?->assignment;

        return $assignment === null ? false : ClassroomAccess::manageResponse($user, $assignment);
    }
}
