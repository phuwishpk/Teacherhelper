<?php

namespace App\Http\Resources;

use App\Models\RubricCriterion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, position, description, points, is_core, source}
 *
 * @mixin RubricCriterion
 */
class RubricCriterionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'description' => $this->description,
            'points' => (float) $this->points,
            'is_core' => (bool) $this->is_core,
            'source' => $this->source,
        ];
    }
}
