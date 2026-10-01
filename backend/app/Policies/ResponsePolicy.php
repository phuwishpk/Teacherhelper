<?php

namespace App\Policies;

use App\Models\Response;
use App\Models\User;
use Illuminate\Auth\Access\Response as AccessResponse;

/**
 * A response is reviewed by the manager of its assignment and read by the
 * classroom's homeroom teacher too (DESIGN §24.8); the student may see their
 * own crop and appeal only after the submission is published (§9, §9.5, §9.7).
 */
class ResponsePolicy
{
    public function view(User $user, Response $response): bool
    {
        $submission = $response->submission;

        return $submission !== null && SubmissionPolicy::teacherSees($user, $submission);
    }

    /** PATCH, regenerate-explanation, resolve an exam mark (§9.5, §22.11). */
    public function review(User $user, Response $response): AccessResponse|bool
    {
        $submission = $response->submission;

        return $submission === null ? false : SubmissionPolicy::teacherManages($user, $submission);
    }

    public function viewCrop(User $user, Response $response): bool
    {
        $submission = $response->submission;

        return $submission !== null
            && (SubmissionPolicy::teacherSees($user, $submission) || SubmissionPolicy::studentOwnsPublished($user, $submission));
    }

    /** POST /student/responses/{id}/appeal (§9.7): the student's own, published answer. */
    public function appeal(User $user, Response $response): bool
    {
        $submission = $response->submission;

        return $submission !== null && SubmissionPolicy::studentOwnsPublished($user, $submission);
    }
}
