<?php

namespace App\Policies;

use App\Models\User;

/**
 * /google/connect, /google/status, /google/disconnect, /google/courses
 * (DESIGN §18.6): only teachers connect a Google account (§18.1), and only
 * their own (the routes never take a user id).
 */
class GoogleAccountPolicy
{
    public function manage(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }
}
