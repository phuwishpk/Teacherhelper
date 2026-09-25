<?php

namespace App\Http\Resources;

use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, assignment_id, position, type, prompt_text, has_prompt_image,
 *  max_points, answer_lines, is_numeric, match_mode, answer_key,
 *  rubric_status, skills?: [...], rubric_criteria?: [...]}
 *
 * Numbers are JSON numbers (never decimal strings).
 *
 * @mixin Question
 */
class QuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'position' => $this->position,
            'type' => $this->type,
            'prompt_text' => $this->prompt_text,
            'has_prompt_image' => $this->prompt_image_path !== null,
            'max_points' => (float) $this->max_points,
            'answer_lines' => $this->answer_lines,
            'is_numeric' => (bool) $this->is_numeric,
            'match_mode' => $this->match_mode,
            'answer_key' => $this->answer_key,
            'rubric_status' => $this->rubric_status,
            'skills' => SkillResource::collection($this->whenLoaded('skills')),
            'rubric_criteria' => RubricCriterionResource::collection($this->whenLoaded('rubricCriteria')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
