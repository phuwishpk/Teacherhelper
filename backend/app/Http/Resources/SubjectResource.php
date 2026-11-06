<?php

namespace App\Http\Resources;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, code, name, is_own}: is_own marks a subject group the signed-in
 * teacher added (DESIGN §29.4).
 *
 * @mixin Subject
 */
class SubjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_own' => $this->owner_user_id !== null && $this->owner_user_id === $request->user()?->id,
        ];
    }
}
