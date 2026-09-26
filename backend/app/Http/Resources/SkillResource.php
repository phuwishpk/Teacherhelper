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

    /**
     * The compact form embedded in Phase 6 payloads: {id, code, name, subject_id, grade_level}.
     *
     * @return array{id: int, code: string, name: string, subject_id: int, grade_level: int|null}
     */
    public static function summary(Skill $skill): array
    {
        return [
            'id' => $skill->id,
            'code' => $skill->code,
            'name' => $skill->name,
            'subject_id' => $skill->subject_id,
            'grade_level' => $skill->grade_level,
        ];
    }
}
