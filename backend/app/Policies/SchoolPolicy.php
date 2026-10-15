<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

/**
 * The teachers' workspaces (`schools`, DESIGN §29.13.1) are seen only in the
 * Filament admin (§7.5), by every active admin: one admin level.
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
        return $this->view($user, $school);
    }

    /** "ลบการเชื่อม Google ของนักเรียนทั้งหมด" (DESIGN §24.9.2). */
    public function unlinkStudentGoogle(User $user, School $school): bool
    {
        return $this->view($user, $school);
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
