<?php

namespace App\Policies;

use App\Domain\Classrooms\ClassroomAccess;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * DESIGN §9, §24.8: an assignment is managed by the creator of its course
 * while the course is bound to the classroom (the homeroom teacher for work
 * without a course), and its results are seen by its manager and by the
 * classroom's homeroom teacher, read-only (ClassroomAccess). Every §9.3
 * action on an assignment (questions, rubric, layout, worksheets) goes
 * through `update` / `view`. A homeroom teacher who sees the work but does
 * not manage it gets 403 not_course_teacher.
 */
class AssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return ClassroomAccess::isSchoolTeacher($user);
    }

    /** The classroom and course are checked when it is created (AssignmentController::store). */
    public function create(User $user): bool
    {
        return ClassroomAccess::isSchoolTeacher($user);
    }

    /** Read the assignment and its results (submissions, scores, images, analytics). */
    public function view(User $user, Assignment $assignment): bool
    {
        return ClassroomAccess::seesResults($user, $assignment);
    }

    public function update(User $user, Assignment $assignment): Response|bool
    {
        return ClassroomAccess::manageResponse($user, $assignment);
    }

    public function delete(User $user, Assignment $assignment): Response|bool
    {
        return ClassroomAccess::manageResponse($user, $assignment);
    }

    /** Build a layout version and print worksheets. */
    public function print(User $user, Assignment $assignment): Response|bool
    {
        return ClassroomAccess::manageResponse($user, $assignment);
    }

    /** Upload scanned pages of this assignment's worksheets (POST /scans). */
    public function scan(User $user, Assignment $assignment): Response|bool
    {
        return ClassroomAccess::manageResponse($user, $assignment);
    }

    /** Review AI grading: requeue, approve, publish, regrade (§9.5, §13). */
    public function review(User $user, Assignment $assignment): Response|bool
    {
        return ClassroomAccess::manageResponse($user, $assignment);
    }

    /** Post to Google Classroom, sync its submissions, retry grades (§18.6). */
    public function manageGoogle(User $user, Assignment $assignment): Response|bool
    {
        return ClassroomAccess::manageResponse($user, $assignment);
    }

    /** Item statistics, the error heatmap and the score distribution (§9.6, §14.3, §20.4). */
    public function viewAnalytics(User $user, Assignment $assignment): bool
    {
        return ClassroomAccess::seesResults($user, $assignment);
    }

    /** The teacher manages the assignment (DESIGN §24.8). */
    public static function owns(User $user, Assignment $assignment): bool
    {
        return ClassroomAccess::manages($user, $assignment);
    }
}
