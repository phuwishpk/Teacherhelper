<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

/** A question belongs to its assignment: same rule as AssignmentPolicy. */
class QuestionPolicy
{
    public function update(User $user, Question $question): bool
    {
        return $question->assignment !== null && AssignmentPolicy::owns($user, $question->assignment);
    }

    public function delete(User $user, Question $question): bool
    {
        return $this->update($user, $question);
    }

    /** Request an AI draft or approve the rubric. */
    public function manageRubric(User $user, Question $question): bool
    {
        return $this->update($user, $question);
    }
}
