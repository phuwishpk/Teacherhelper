<?php

namespace App\Policies;

use App\Domain\Classrooms\ClassroomAccess;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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
     * Re-issue the QR login card or reset the PIN of a student: the student's
     * editors (DESIGN §24.2), i.e. the homeroom teacher of an open classroom
     * the student is in, in the same school.
     */
    public function manageStudentCredentials(User $user, User $student): bool
    {
        return self::editsStudent($user, $student);
    }

    /** PATCH /students/{id} (name, student code, DESIGN §24.4): the same editors. */
    public function editStudent(User $user, User $student): bool
    {
        return self::editsStudent($user, $student);
    }

    /**
     * Filament "ยกเลิกการเชื่อม Google" of a student (DESIGN §24.9.5): an
     * active admin of the student's school, or a system admin. Teachers use
     * DELETE /students/{id}/google-identity (editStudent).
     */
    public function unlinkGoogle(User $user, User $student): bool
    {
        return $this->isAdmin($user) && $student->isStudent()
            && ($user->school_id === null || $user->school_id === $student->school_id);
    }

    /**
     * Merge $merge into $keep (DESIGN §24.5): an admin of their school (or a
     * system admin), or a teacher who is the homeroom teacher of a classroom
     * (open or closed) of each of the two accounts. A subject teacher cannot,
     * because merging edits student data (#65).
     */
    public function mergeStudents(User $user, User $keep, User $merge): bool
    {
        if ($user->isAdmin()) {
            return $user->isActive() && ($user->school_id === null || ($user->school_id === $keep->school_id && $user->school_id === $merge->school_id));
        }

        return self::isHomeroomOf($user, $keep, false) && self::isHomeroomOf($user, $merge, false);
    }

    /**
     * GET /students/{id}/mastery and indicator-progress (DESIGN §9.6, §24.8):
     * a homeroom teacher of any classroom of the student (open or closed), or
     * a subject teacher of one (who must then name an own course).
     */
    public function viewMastery(User $user, User $student): bool
    {
        return ClassroomAccess::forStudent($user, $student) !== null;
    }

    /** The per-student AI analysis (§20.5): homeroom teachers only, 403 not_homeroom_teacher for a subject teacher (§24.8). */
    public function viewAnalysis(User $user, User $student): Response|bool
    {
        $access = ClassroomAccess::forStudent($user, $student);
        if ($access === null) {
            return false;
        }

        return $access['homeroom'] ? true : ClassroomAccess::denyNotHomeroom();
    }

    /** The editors of a student (DESIGN §24.2): the homeroom teacher of an open classroom of theirs. */
    public static function editsStudent(User $user, User $student): bool
    {
        return self::isHomeroomOf($user, $student, true);
    }

    private static function isHomeroomOf(User $user, User $student, bool $openOnly): bool
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
            ->when($openOnly, fn ($q) => $q->whereNull('closed_at'))
            ->whereHas('students', fn ($q) => $q->whereKey($student->id))
            ->exists();
    }

    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->isActive();
    }
}
