<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

/**
 * Schools are managed only in the Filament admin (DESIGN §7.5).
 */
class SchoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, School $school): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, School $school): bool
    {
        return $this->isAdmin($user);
    }

    /** Teachers, classrooms and skills reference schools with ON DELETE RESTRICT. */
    public function delete(User $user, School $school): bool
    {
        return false;
    }

    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->isActive();
    }
}
