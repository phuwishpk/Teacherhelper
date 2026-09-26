<?php

namespace App\Http\Resources;

use App\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, classroom_id, subject_id, title, strictness, status,
 *  current_layout_version, due_at, questions_count?, missing_ai_key_count?,
 *  classroom?: {id, name},
 *  subject?: {id, code, name}, google_link?: {course_work_id, alternate_link,
 *  drive_file_id, has_blank_worksheet, posted_at}|null, questions?: [...],
 *  created_by, created_at, updated_at}
 *
 * missing_ai_key_count (detail only): answers waiting as `manual` because no
 * Gemini key was usable (DESIGN §13 banner; POST .../requeue-missing-key).
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
            'missing_ai_key_count' => $this->whenCounted('missing_ai_key_count'),
            'classroom' => $this->whenLoaded('classroom', fn () => [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
            ]),
            'subject' => $this->whenLoaded('subject', fn () => [
                'id' => $this->subject->id,
                'code' => $this->subject->code,
                'name' => $this->subject->name,
            ]),
            // DESIGN §18.4 assignment_google_links: {course_work_id, alternate_link, drive_file_id, has_blank_worksheet, posted_at} | null
            'google_link' => $this->whenLoaded('googleLink', fn () => $this->googleLink?->toApi()),
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
