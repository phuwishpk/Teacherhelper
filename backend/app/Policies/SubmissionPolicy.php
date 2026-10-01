<?php

namespace App\Policies;

use App\Domain\Classrooms\ClassroomAccess;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * DESIGN §9, §24.8: the assignment's manager and the classroom's homeroom
 * teacher see every submission of it (the homeroom teacher read-only); a
 * student sees only their own, and only once published.
 */
class SubmissionPolicy
{
    public function view(User $user, Submission $submission): bool
    {
        return self::teacherSees($user, $submission) || self::studentOwnsPublished($user, $submission);
    }

    /** POST /submissions/{id}/publish (§9.5). */
    public function publish(User $user, Submission $submission): Response|bool
    {
        return self::teacherManages($user, $submission);
    }

    /** POST /submissions/{id}/grade (§19.9): a new whole-page hand-in. */
    public function grade(User $user, Submission $submission): Response|bool
    {
        return self::teacherManages($user, $submission);
    }

    /** The teacher manages the submission's assignment (true), sees it only (403 not_course_teacher) or neither (false). */
    public static function teacherManages(User $user, Submission $submission): Response|bool
    {
        $assignment = $submission->assignment;

        return $assignment !== null && $user->isTeacher() ? ClassroomAccess::manageResponse($user, $assignment) : false;
    }

    /** The teacher manages the submission's assignment. */
    public static function teacherOwns(User $user, Submission $submission): bool
    {
        $assignment = $submission->assignment;

        return $assignment !== null && ClassroomAccess::manages($user, $assignment);
    }

    /** The teacher sees the submission's results (manager or homeroom teacher). */
    public static function teacherSees(User $user, Submission $submission): bool
    {
        $assignment = $submission->assignment;

        return $assignment !== null && $user->isTeacher() && ClassroomAccess::seesResults($user, $assignment);
    }

    public static function studentOwnsPublished(User $user, Submission $submission): bool
    {
        return $user->isStudent()
            && $user->isActive()
            && $submission->student_id === $user->id
            && $submission->isPublished();
    }
}
