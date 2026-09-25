<?php

namespace App\Policies;

use App\Models\Response;
use App\Models\User;

/**
 * A response is the teacher's to review (AssignmentPolicy::owns); the student
 * may see their own crop only after the submission is published (DESIGN §9,
 * §9.7).
 */
class ResponsePolicy
{
    public function view(User $user, Response $response): bool
    {
        $submission = $response->submission;

        return $submission !== null && SubmissionPolicy::teacherOwns($user, $submission);
    }

    public function viewCrop(User $user, Response $response): bool
    {
        $submission = $response->submission;

        return $submission !== null
            && (SubmissionPolicy::teacherOwns($user, $submission) || SubmissionPolicy::studentOwnsPublished($user, $submission));
    }
}
