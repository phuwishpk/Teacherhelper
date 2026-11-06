<?php

namespace App\Policies;

use App\Models\GoogleEvent;
use App\Models\User;

/** The Google problems (DESIGN §32) are read by admins only and never changed. */
class GoogleEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->isActive();
    }

    public function view(User $user, GoogleEvent $event): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GoogleEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, GoogleEvent $event): bool
    {
        return false;
    }
}
