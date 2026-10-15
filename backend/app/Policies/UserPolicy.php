<?php

namespace App\Policies;

use App\Domain\Accounts\AccountDeletion;
use App\Domain\Auth\Impersonations;
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

    /** Every active admin sees and edits every account (one admin level, DESIGN §29.13.1). */
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
        return $this->view($user, $target);
    }

    /** Filament's own delete stays off: accounts go through "ลบบัญชี" (deleteAccount, DESIGN §31). */
    public function delete(User $user, User $target): bool
    {
        return false;
    }

    /** "ลบบัญชี" (DESIGN §31): a teacher or student, not the admin themself, not merged, not deleted yet. */
    public function deleteAccount(User $user, User $target): bool
    {
        return $this->isAdmin($user) && AccountDeletion::deletable($user, $target);
    }

    /** "กู้คืนบัญชี" within the 30 days (DESIGN §31). */
    public function restoreAccount(User $user, User $target): bool
    {
        return $this->isAdmin($user) && $target->isDeleted();
    }

    /** Filament "approve": a pending or disabled teacher becomes active. */
    public function approve(User $user, User $target): bool
    {
        return $this->view($user, $target) && $target->isTeacher() && ! $target->isActive() && ! $target->isDeleted();
    }

    /** Filament "เข้าใช้แทน" (DESIGN §29.14): an active teacher or student, never another admin. */
    public function impersonate(User $user, User $target): bool
    {
        return $this->isAdmin($user) && Impersonations::allowed($user, $target);
    }

    /** Filament "disable": an active teacher or student loses access (tokens revoked). */
    public function disable(User $user, User $target): bool
    {
        return $this->view($user, $target) && ($target->isTeacher() || $target->isStudent())
            && $target->isActive() && ! $target->isMerged();
    }

    /** Filament "เปิดใช้งาน" of a disabled student (teachers come back with approve). */
    public function enableStudent(User $user, User $student): bool
    {
        return $this->isAdmin($user) && $student->isStudent() && ! $student->isActive() && ! $student->isMerged() && ! $student->isDeleted();
    }

    /**
     * Filament "แก้ไข" (name, student code, username) and "รีเซ็ตรหัสผ่าน"
     * of a student: any active admin, whichever classrooms the student is in.
     * A merged account is evidence of the merge (§24.5) and stays as it is; a
     * deleted one (§31) is restored first.
     */
    public function adminManageStudent(User $user, User $student): bool
    {
        return $this->isAdmin($user) && $student->isStudent() && ! $student->isMerged() && ! $student->isDeleted();
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
     * active admin. Teachers use DELETE /students/{id}/google-identity (editStudent).
     */
    public function unlinkGoogle(User $user, User $student): bool
    {
        return $this->isAdmin($user) && $student->isStudent();
    }

    /**
     * Merge $merge into $keep (DESIGN §24.5): an admin, or a teacher who is
     * the homeroom teacher of a classroom (open or closed) of each of the two
     * accounts. A subject teacher cannot, because merging edits student data (#65).
     */
    public function mergeStudents(User $user, User $keep, User $merge): bool
    {
        if ($user->isAdmin()) {
            return $user->isActive();
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
        if (! $user->isTeacher() || ! $user->isActive() || ! $student->isStudent()) {
            return false;
        }

        // Any classroom of theirs: pupils come from many workspaces (DESIGN §29.13.3).
        return Classroom::query()
            ->where('teacher_id', $user->id)
            ->when($openOnly, fn ($q) => $q->whereNull('closed_at'))
            ->whereHas('students', fn ($q) => $q->whereKey($student->id))
            ->exists();
    }

    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->isActive();
    }
}
