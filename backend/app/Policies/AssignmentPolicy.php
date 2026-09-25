<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;

/**
 * DESIGN §9: a teacher reaches only the assignments of classrooms they teach
 * in their own school. Every §9.3 action on an assignment (questions, rubric,
 * layout, worksheets) goes through `update` / `view`.
 */
class AssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return self::isSchoolTeacher($user);
    }

    public function create(User $user): bool
    {
        return self::isSchoolTeacher($user);
    }

    public function view(User $user, Assignment $assignment): bool
    {
        return self::owns($user, $assignment);
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return self::owns($user, $assignment);
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return self::owns($user, $assignment);
    }

    /** Build a layout version and print worksheets. */
    public function print(User $user, Assignment $assignment): bool
    {
        return self::owns($user, $assignment);
    }

    public static function owns(User $user, Assignment $assignment): bool
    {
        return self::isSchoolTeacher($user)
            && $assignment->school_id === $user->school_id
            && $assignment->classroom?->teacher_id === $user->id;
    }

    private static function isSchoolTeacher(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }
}
