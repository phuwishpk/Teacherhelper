<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

/**
 * DESIGN §20.7, §20.9: a course, with its units and lesson plans, belongs
 * to the teacher who created it; other teachers never see it.
 */
class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isSchoolTeacher($user);
    }

    public function create(User $user): bool
    {
        return $this->isSchoolTeacher($user);
    }

    public function view(User $user, Course $course): bool
    {
        return $this->owns($user, $course);
    }

    /** Also: its classrooms, indicators, units and lesson plans. */
    public function update(User $user, Course $course): bool
    {
        return $this->owns($user, $course);
    }

    public function delete(User $user, Course $course): bool
    {
        return $this->owns($user, $course);
    }

    private function owns(User $user, Course $course): bool
    {
        return $this->isSchoolTeacher($user)
            && $course->created_by === $user->id
            && $course->school_id === $user->school_id;
    }

    private function isSchoolTeacher(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }
}
