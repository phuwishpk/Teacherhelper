<?php

namespace App\Http\Resources;

use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, code, name, subject_id, parent_id, school_id, grade_level, level,
 *  source, source_label, created_by}
 *
 * level strand|standard|indicator|sub_indicator; source
 * curriculum|school_admin|teacher; source_label "ครูเพิ่มเอง" for an
 * indicator a teacher added (the app shows it as a label, DESIGN §20.2),
 * null otherwise.
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
            'level' => $this->level,
            'source' => $this->source,
            'source_label' => $this->resource->sourceLabel(),
            'created_by' => $this->created_by,
        ];
    }

    /**
     * The form of a course, unit or lesson plan's indicators (DESIGN §20.7):
     * {id, code, name, subject_id, grade_level, level, source_label}.
     *
     * @return array{id: int, code: string, name: string, subject_id: int, grade_level: int|null, level: string, source_label: string|null}
     */
    public static function indicator(Skill $skill): array
    {
        return self::summary($skill) + [
            'level' => $skill->level,
            'source_label' => $skill->sourceLabel(),
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
