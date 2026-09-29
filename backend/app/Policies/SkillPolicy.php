<?php

namespace App\Policies;

use App\Models\Skill;
use App\Models\User;

/**
 * DESIGN §2.3 / §20.2: the curriculum changes only through the CSV import
 * (admin). A teacher may add a missing indicator for their school (shared
 * with every teacher there) and rename it while no answer was observed on
 * it (the controller checks the observations: 409 skill_in_use). An admin
 * may edit a school's own rows at any time, in Filament.
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

    /** POST /skills: an indicator of the teacher's school (source teacher). */
    public function create(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }

    public function update(User $user, Skill $skill): bool
    {
        if (! $user->isActive() || $skill->school_id === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isTeacher()
            && $skill->source === Skill::SOURCE_TEACHER
            && $skill->created_by === $user->id
            && $skill->school_id === $user->school_id;
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
