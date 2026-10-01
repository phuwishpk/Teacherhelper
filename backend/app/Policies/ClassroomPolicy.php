<?php

namespace App\Policies;

use App\Domain\Classrooms\ClassroomAccess;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * DESIGN §9, §24.8: a teacher reaches the classrooms of their school they
 * are the homeroom teacher of, or a subject teacher of (an own course bound
 * to it, ClassroomAccess). The roster and student data are the homeroom
 * teacher's: a subject teacher reads the roster only and gets 403
 * not_homeroom_teacher on everything else. Admins act only in Filament (the
 * API's teacher routes need role:teacher): they list classrooms, close,
 * reopen or delete them and bind subject teachers (§24.7).
 */
class ClassroomPolicy
{
    public function viewAny(User $user): bool
    {
        return ClassroomAccess::isSchoolTeacher($user);
    }

    public function view(User $user, Classroom $classroom): bool
    {
        return ClassroomAccess::for($user, $classroom) !== null;
    }

    public function create(User $user): bool
    {
        return ClassroomAccess::isSchoolTeacher($user);
    }

    public function update(User $user, Classroom $classroom): Response|bool
    {
        return $this->homeroom($user, $classroom);
    }

    /** The roster (names, numbers, student codes): a subject teacher reads it without PIN/Google state. */
    public function viewRoster(User $user, Classroom $classroom): bool
    {
        return ClassroomAccess::for($user, $classroom) !== null;
    }

    /** Add, take out and renumber students, issue pending PINs (§24.4). */
    public function manageStudents(User $user, Classroom $classroom): Response|bool
    {
        return $this->homeroom($user, $classroom);
    }

    /** Queue the QR login-card PDF of the whole classroom. */
    public function printLoginCards(User $user, Classroom $classroom): Response|bool
    {
        return $this->homeroom($user, $classroom);
    }

    /** Link a Google Classroom course and match its students (§18.6; subject teachers' courses: build 4). */
    public function manageGoogle(User $user, Classroom $classroom): Response|bool
    {
        return $this->homeroom($user, $classroom);
    }

    /** The per-student AI analysis (§20.5): the homeroom teacher only (§24.8). */
    public function viewAnalyses(User $user, Classroom $classroom): Response|bool
    {
        return $this->homeroom($user, $classroom);
    }

    /**
     * Close ("ห้องเก่า"), reopen and delete the classroom (DESIGN §24.6, §24.8):
     * its homeroom teacher in the app, or an admin of its school in Filament.
     */
    public function close(User $user, Classroom $classroom): Response|bool
    {
        return $this->administers($user, $classroom) ?: $this->homeroom($user, $classroom);
    }

    public function reopen(User $user, Classroom $classroom): Response|bool
    {
        return $this->administers($user, $classroom) ?: $this->homeroom($user, $classroom);
    }

    public function delete(User $user, Classroom $classroom): Response|bool
    {
        return $this->administers($user, $classroom) ?: $this->homeroom($user, $classroom);
    }

    /** Bind a subject teacher's course directly (Filament "เพิ่มครูประจำวิชา", §24.7). */
    public function assignCourses(User $user, Classroom $classroom): bool
    {
        return $this->administers($user, $classroom);
    }

    /**
     * Unbind a course from the classroom (§24.7 step 5): the homeroom teacher,
     * the course's creator (checked by the controller with the course) or an admin.
     */
    public function unbindCourse(User $user, Classroom $classroom): bool
    {
        return $this->administers($user, $classroom) || ClassroomAccess::for($user, $classroom) !== null;
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

    /**
     * The student x skill heatmap and the classroom charts (§9.6, §14.3,
     * §20.4): both roles; a subject teacher must name an own course
     * (checked by ChartController::courseOf).
     */
    public function viewMastery(User $user, Classroom $classroom): bool
    {
        return ClassroomAccess::for($user, $classroom) !== null;
    }

    /** Allowed for the homeroom teacher, 403 not_homeroom_teacher for a subject teacher, else denied. */
    private function homeroom(User $user, Classroom $classroom): Response|bool
    {
        $access = ClassroomAccess::for($user, $classroom);
        if ($access === null) {
            return false;
        }

        return $access->isHomeroom() ? true : ClassroomAccess::denyNotHomeroom();
    }

    private function administers(User $user, Classroom $classroom): bool
    {
        return $user->isAdmin() && $user->isActive()
            && ($user->school_id === null || $user->school_id === $classroom->school_id);
    }
}
