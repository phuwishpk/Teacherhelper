<?php

namespace App\Http\Resources;

use App\Models\PracticeItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A practice item as the teacher sees it (DESIGN §9.6):
 * {id, school_id, skill_id, skill: {id, code, name, subject_id, grade_level},
 *  answer_type, prompt_text, options, answer_key, explanation, status,
 *  source, approved_by, approved_at, created_at, updated_at}.
 * Students get forStudent(): no answer_key, no explanation (§14.1).
 *
 * @mixin PracticeItem
 */
class PracticeItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'skill_id' => $this->skill_id,
            'skill' => $this->whenLoaded('skill', fn () => $this->skill === null ? null : SkillResource::summary($this->skill)),
            'answer_type' => $this->answer_type,
            'prompt_text' => $this->prompt_text,
            'options' => $this->options,
            'answer_key' => $this->answer_key,
            'explanation' => $this->explanation,
            'status' => $this->status,
            'source' => $this->source,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, skill_id: int, answer_type: string, prompt_text: string, options: list<array{key: string, text: string}>|null}
     */
    public static function forStudent(PracticeItem $item): array
    {
        return [
            'id' => $item->id,
            'skill_id' => $item->skill_id,
            'answer_type' => $item->answer_type,
            'prompt_text' => $item->prompt_text,
            'options' => $item->answer_type === PracticeItem::TYPE_MCQ ? $item->options : null,
        ];
    }
}
