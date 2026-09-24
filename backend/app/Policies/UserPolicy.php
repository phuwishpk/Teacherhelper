<?php

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;

/**
 * Admin abilities (Filament UserResource, DESIGN §7.5) and the teacher-side
 * student abilities of DESIGN §9.2 (login card, PIN) in one policy, because
 * both act on the `users` table.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, User $target): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, User $target): bool
    {
        return $this->isAdmin($user);
    }

    /** Accounts are disabled, never deleted (FKs from classrooms and scores). */
    public function delete(User $user, User $target): bool
    {
        return false;
    }

    /** Filament "approve": a pending or disabled teacher becomes active. */
    public function approve(User $user, User $target): bool
    {
        return $this->isAdmin($user) && $target->isTeacher() && ! $target->isActive();
    }

    /** Filament "disable": an active teacher loses access (tokens revoked). */
    public function disable(User $user, User $target): bool
    {
        return $this->isAdmin($user) && $target->isTeacher() && $target->isActive();
    }

    /**
     * Re-issue the QR login card or reset the PIN of a student: only a teacher
     * who teaches a classroom the student belongs to, in the same school.
     */
    public function manageStudentCredentials(User $user, User $student): bool
    {
        if (! $user->isTeacher() || ! $user->isActive() || $user->school_id === null) {
            return false;
        }

        if (! $student->isStudent() || $student->school_id !== $user->school_id) {
            return false;
        }

        return Classroom::query()
            ->where('teacher_id', $user->id)
            ->where('school_id', $user->school_id)
            ->whereHas('students', fn ($q) => $q->whereKey($student->id))
            ->exists();
    }

    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->isActive();
    }
}
