<?php

namespace App\Domain\Students;

use App\Domain\Classrooms\ClassroomAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * DESIGN §29.13.3: the student accounts a teacher may see. A pupil has one
 * account and sits in the classrooms of many teachers, so "the school's
 * students" of §24.4 becomes: the accounts made in the teacher's own
 * workspace, plus everybody in a classroom the teacher teaches.
 */
final class StudentVisibility
{
    /** @return Builder<User> student accounts (merged ones included) */
    public static function query(User $teacher): Builder
    {
        return User::query()
            ->where('users.role', User::ROLE_STUDENT)
            ->where(fn (Builder $q) => $q
                ->where('users.school_id', $teacher->school_id)
                ->orWhereIn('users.id', DB::table('classroom_students')
                    ->select('student_id')
                    ->whereIn('classroom_id', ClassroomAccess::classrooms($teacher)->select('classrooms.id'))));
    }

    public static function sees(User $teacher, User $student): bool
    {
        return self::query($teacher)->whereKey($student->id)->exists();
    }
}
