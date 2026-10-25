<?php

namespace App\Policies;

use App\Models\PracticeItem;
use App\Models\User;

/**
 * DESIGN §9.6, §14.1: the practice bank is shared by the school. Any active
 * teacher of the school lists, writes and edits its items; approving, and
 * changing the content of an item that stays approved, needs a teacher of
 * the skill's subject (PracticeBank::teachesSubjectOf, checked in the
 * service so the error can say why).
 */
class PracticeItemPolicy
{
    public function viewAny(User $user): bool
    {
        return self::isSchoolTeacher($user);
    }

    public function view(User $user, PracticeItem $item): bool
    {
        return self::isSchoolTeacher($user) && $item->school_id === $user->school_id;
    }

    public function create(User $user): bool
    {
        return self::isSchoolTeacher($user);
    }

    /** Edit, approve or retire (§9.6 PATCH). */
    public function update(User $user, PracticeItem $item): bool
    {
        return $this->view($user, $item);
    }

    /** Queue Gemini drafts for a skill (POST /skills/{id}/practice-items/generate). */
    public function generate(User $user): bool
    {
        return self::isSchoolTeacher($user);
    }

    private static function isSchoolTeacher(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }
}
