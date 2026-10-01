<?php

namespace App\Policies;

use App\Domain\Classrooms\ClassroomAccess;
use App\Models\Question;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** A question belongs to its assignment: same rules as AssignmentPolicy. */
class QuestionPolicy
{
    /** Read the question's image (exams, §22.4): whoever sees the assignment. */
    public function view(User $user, Question $question): bool
    {
        return $question->assignment !== null && ClassroomAccess::seesResults($user, $question->assignment);
    }

    public function update(User $user, Question $question): Response|bool
    {
        return $question->assignment === null ? false : ClassroomAccess::manageResponse($user, $question->assignment);
    }

    public function delete(User $user, Question $question): Response|bool
    {
        return $this->update($user, $question);
    }

    /** Request an AI draft or approve the rubric. */
    public function manageRubric(User $user, Question $question): Response|bool
    {
        return $this->update($user, $question);
    }
}
