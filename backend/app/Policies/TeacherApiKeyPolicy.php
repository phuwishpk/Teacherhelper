<?php

namespace App\Policies;

use App\Models\User;

/**
 * GET / PUT / DELETE /me/ai-key (DESIGN §9.1): an active school teacher
 * manages only their own key (the routes never take a user id).
 */
class TeacherApiKeyPolicy
{
    public function manage(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }
}
