<?php

namespace App\Policies;

use App\Models\AssistantConversation;
use App\Models\User;

/** Admins read "ผู้ช่วย Krucheck" conversations in Filament (DESIGN §37) and never change them; teachers reach their own through the API only. */
class AssistantConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->isActive();
    }

    public function view(User $user, AssistantConversation $conversation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AssistantConversation $conversation): bool
    {
        return false;
    }

    public function delete(User $user, AssistantConversation $conversation): bool
    {
        return false;
    }
}
