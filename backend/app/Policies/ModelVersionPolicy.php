<?php

namespace App\Policies;

use App\Models\ModelVersion;
use App\Models\User;

/**
 * DESIGN §9.8: any active app user (teacher or student) may fetch the active
 * model and its file; only admins manage versions (Filament).
 */
class ModelVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->isActive();
    }

    public function view(User $user, ModelVersion $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ModelVersion $model): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, ModelVersion $model): bool
    {
        return $this->viewAny($user);
    }

    /** GET /ml/models/active and /ml/models/{id}/file. */
    public function download(User $user, ModelVersion $model): bool
    {
        return $user->isActive();
    }
}
