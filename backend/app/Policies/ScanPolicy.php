<?php

namespace App\Policies;

use App\Models\Scan;
use App\Models\User;

/**
 * Scans belong to the teacher who currently teaches the assignment's
 * classroom (AssignmentPolicy::owns, DESIGN §9). Students never see page
 * images: they get their own crops through ResponsePolicy::viewCrop.
 */
class ScanPolicy
{
    public function view(User $user, Scan $scan): bool
    {
        $assignment = $scan->submission?->assignment;

        return $assignment !== null && AssignmentPolicy::owns($user, $assignment);
    }

    public function confirmReplace(User $user, Scan $scan): bool
    {
        return $this->view($user, $scan);
    }
}
