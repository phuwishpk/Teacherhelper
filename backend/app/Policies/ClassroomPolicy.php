<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;

/**
 * DESIGN §9: a teacher reaches only the classrooms they teach in their own school.
 */
class ClassroomPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isSchoolTeacher($user);
    }

    public function view(User $user, Classroom $classroom): bool
    {
        return $this->owns($user, $classroom);
    }

    public function create(User $user): bool
    {
        return $this->isSchoolTeacher($user);
    }

    public function update(User $user, Classroom $classroom): bool
    {
        return $this->owns($user, $classroom);
    }

    /** Bulk-add students and read the roster. */
    public function manageStudents(User $user, Classroom $classroom): bool
    {
        return $this->owns($user, $classroom);
    }

    /** Queue the QR login-card PDF of the whole classroom. */
    public function printLoginCards(User $user, Classroom $classroom): bool
    {
        return $this->owns($user, $classroom);
    }

    private function owns(User $user, Classroom $classroom): bool
    {
        return $this->isSchoolTeacher($user)
            && $classroom->teacher_id === $user->id
            && $classroom->school_id === $user->school_id;
    }

    private function isSchoolTeacher(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }
}
