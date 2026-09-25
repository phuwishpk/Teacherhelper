<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;

/**
 * DESIGN §9: the classroom's teacher sees every submission of the
 * assignment; a student sees only their own, and only once published.
 */
class SubmissionPolicy
{
    public function view(User $user, Submission $submission): bool
    {
        return self::teacherOwns($user, $submission) || self::studentOwnsPublished($user, $submission);
    }

    public static function teacherOwns(User $user, Submission $submission): bool
    {
        $assignment = $submission->assignment;

        return $assignment !== null && AssignmentPolicy::owns($user, $assignment);
    }

    public static function studentOwnsPublished(User $user, Submission $submission): bool
    {
        return $user->isStudent()
            && $user->isActive()
            && $submission->student_id === $user->id
            && $submission->isPublished();
    }
}
