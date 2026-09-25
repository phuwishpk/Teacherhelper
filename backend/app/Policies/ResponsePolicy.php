<?php

namespace App\Policies;

use App\Models\Response;
use App\Models\User;

/**
 * A response is the teacher's to review (AssignmentPolicy::owns); the student
 * may see their own crop and appeal only after the submission is published
 * (DESIGN §9, §9.5, §9.7).
 */
class ResponsePolicy
{
    public function view(User $user, Response $response): bool
    {
        $submission = $response->submission;

        return $submission !== null && SubmissionPolicy::teacherOwns($user, $submission);
    }

    /** PATCH, regenerate-explanation (§9.5). */
    public function review(User $user, Response $response): bool
    {
        return $this->view($user, $response);
    }

    public function viewCrop(User $user, Response $response): bool
    {
        $submission = $response->submission;

        return $submission !== null
            && (SubmissionPolicy::teacherOwns($user, $submission) || SubmissionPolicy::studentOwnsPublished($user, $submission));
    }

    /** POST /student/responses/{id}/appeal (§9.7): the student's own, published answer. */
    public function appeal(User $user, Response $response): bool
    {
        $submission = $response->submission;

        return $submission !== null && SubmissionPolicy::studentOwnsPublished($user, $submission);
    }
}
