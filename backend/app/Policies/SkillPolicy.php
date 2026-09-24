<?php

namespace App\Policies;

use App\Models\Skill;
use App\Models\User;

/**
 * Skills are read-only for everyone (DESIGN §2.3: teachers only pick them);
 * they change only through the CSV import (admin).
 */
class SkillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && ($user->isAdmin() || $user->isTeacher());
    }

    public function view(User $user, Skill $skill): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        // Teachers see curriculum skills and their own school's sub-skills.
        return $user->isAdmin() || $skill->school_id === null || $skill->school_id === $user->school_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Skill $skill): bool
    {
        return false;
    }

    public function delete(User $user, Skill $skill): bool
    {
        return false;
    }

    /** Run the CSV importer from Filament. */
    public function import(User $user): bool
    {
        return $user->isAdmin() && $user->isActive();
    }
}
