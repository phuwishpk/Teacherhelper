<?php

namespace App\Policies;

use App\Models\User;

/**
 * POST /devices: every active teacher or student registers the FCM token of
 * the phone they are signed in on (DESIGN §9.1).
 */
class DeviceTokenPolicy
{
    public function create(User $user): bool
    {
        return $user->isActive() && ($user->isTeacher() || $user->isStudent());
    }
}
