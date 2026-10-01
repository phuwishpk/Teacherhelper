<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

/**
 * Schools are managed only in the Filament admin (DESIGN §7.5). An admin of
 * a school (school_id set) reads and edits only that school, Google sign-in
 * settings included (§24.2); only a system admin creates schools.
 */
class SchoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, School $school): bool
    {
        return $this->isAdmin($user) && ($user->school_id === null || $user->school_id === $school->id);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user) && $user->school_id === null;
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
