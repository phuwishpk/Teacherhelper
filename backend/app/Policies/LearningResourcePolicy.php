<?php

namespace App\Policies;

use App\Models\LearningResource;
use App\Models\User;

/**
 * DESIGN §9.6, §14.1: review links belong to the school; every active
 * teacher of the school manages them.
 */
class LearningResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return self::isSchoolTeacher($user);
    }

    public function create(User $user): bool
    {
        return self::isSchoolTeacher($user);
    }

    public function update(User $user, LearningResource $resource): bool
    {
        return self::isSchoolTeacher($user) && $resource->school_id === $user->school_id;
    }

    public function delete(User $user, LearningResource $resource): bool
    {
        return $this->update($user, $resource);
    }

    private static function isSchoolTeacher(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }
}
