<?php

namespace App\Http\Resources;

use App\Models\LearningResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, skill_id, title, url, added_by, created_at}
 *
 * @mixin LearningResource
 */
class LearningResourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'skill_id' => $this->skill_id,
            'title' => $this->title,
            'url' => $this->url,
            'added_by' => $this->added_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
