<?php

namespace App\Http\Resources;

use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, code, name, subject_id, parent_id, school_id, grade_level}
 *
 * @mixin Skill
 */
class SkillResource extends JsonResource
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
            'subject_id' => $this->subject_id,
            'parent_id' => $this->parent_id,
            'school_id' => $this->school_id,
            'grade_level' => $this->grade_level,
        ];
    }
}
