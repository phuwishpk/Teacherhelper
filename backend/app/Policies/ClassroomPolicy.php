<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;

/**
 * DESIGN §9: a teacher reaches only the classrooms they teach in their own school.
 * Admins act only in Filament (the API's teacher routes need role:teacher):
 * they list classrooms and close, reopen or delete them (§24.8).
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

    /** Link a Google Classroom course and match its students (§18.6). */
    public function manageGoogle(User $user, Classroom $classroom): bool
    {
        return $this->owns($user, $classroom);
    }

    /**
     * Close ("ห้องเก่า"), reopen and delete the classroom (DESIGN §24.6, §24.8):
     * its homeroom teacher in the app, or an admin of its school in Filament.
     */
    public function close(User $user, Classroom $classroom): bool
    {
        return $this->owns($user, $classroom) || $this->administers($user, $classroom);
    }

    public function reopen(User $user, Classroom $classroom): bool
    {
        return $this->owns($user, $classroom) || $this->administers($user, $classroom);
    }

    public function delete(User $user, Classroom $classroom): bool
    {
        return $this->owns($user, $classroom) || $this->administers($user, $classroom);
    }

    /** The Filament classroom list (DESIGN §24.13): any active admin, scoped to their school there. */
    public function adminViewAny(User $user): bool
    {
        return $user->isAdmin() && $user->isActive();
    }

    /** One classroom in Filament: an admin of its school, or a system admin (school_id null). */
    public function adminView(User $user, Classroom $classroom): bool
    {
        return $this->administers($user, $classroom);
    }

    /** The student x skill mastery heatmap (§9.6, §14.3). */
    public function viewMastery(User $user, Classroom $classroom): bool
    {
        return $this->owns($user, $classroom);
    }

    private function owns(User $user, Classroom $classroom): bool
    {
        return $this->isSchoolTeacher($user)
            && $classroom->teacher_id === $user->id
            && $classroom->school_id === $user->school_id;
    }

    private function administers(User $user, Classroom $classroom): bool
    {
        return $user->isAdmin() && $user->isActive()
            && ($user->school_id === null || $user->school_id === $classroom->school_id);
    }

    private function isSchoolTeacher(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }
}
