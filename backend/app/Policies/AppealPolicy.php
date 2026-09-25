<?php

namespace App\Policies;

use App\Models\Appeal;
use App\Models\User;

/**
 * Appeals (DESIGN §9.5, §13): the teacher of the classroom lists and answers
 * them; the student who filed one sees it inside their own results only.
 */
class AppealPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }

    public function view(User $user, Appeal $appeal): bool
    {
        $submission = $appeal->response?->submission;

        return $submission !== null
            && (SubmissionPolicy::teacherOwns($user, $submission) || SubmissionPolicy::studentOwnsPublished($user, $submission));
    }

    public function resolve(User $user, Appeal $appeal): bool
    {
        $submission = $appeal->response?->submission;

        return $submission !== null && SubmissionPolicy::teacherOwns($user, $submission);
    }
}
