<?php

namespace App\Http\Resources;

use App\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, classroom_id, subject_id, title, strictness, status,
 *  current_layout_version, due_at, questions_count?, classroom?: {id, name},
 *  subject?: {id, code, name}, questions?: [...], created_by, created_at, updated_at}
 *
 * due_at and timestamps are UTC ISO 8601; the app shows Asia/Bangkok.
 *
 * @mixin Assignment
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'subject_id' => $this->subject_id,
            'title' => $this->title,
            'strictness' => $this->strictness,
            'status' => $this->status,
            'current_layout_version' => $this->current_layout_version,
            'due_at' => $this->due_at?->toIso8601String(),
            'questions_count' => $this->whenCounted('questions'),
            'classroom' => $this->whenLoaded('classroom', fn () => [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
            ]),
            'subject' => $this->whenLoaded('subject', fn () => [
                'id' => $this->subject->id,
                'code' => $this->subject->code,
                'name' => $this->subject->name,
            ]),
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
