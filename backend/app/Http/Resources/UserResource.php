<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, name, email, role, status, school: {id, name} | null}
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            // A student on the initial password changes it before anything else (DESIGN §29.10).
            'must_change_password' => $this->isStudent() && (bool) $this->credential?->must_change_password,
            'role' => $this->role,
            'status' => $this->status,
            // Teachers only: the id of their workspace and the school name they typed
            // (DESIGN §29.13.2); the workspace row itself is never shown.
            'school' => $this->isTeacher() && $this->school_id !== null ? [
                'id' => $this->school_id,
                'name' => $this->school_name,
            ] : null,
        ];
    }
}
