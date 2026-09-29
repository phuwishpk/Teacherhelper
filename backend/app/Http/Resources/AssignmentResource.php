<?php

namespace App\Http\Resources;

use App\Models\Assignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, classroom_id, subject_id, title, strictness, status,
 *  current_layout_version, due_at, mode, source, accept_late, score_only,
 *  key_origin, key_approved_at, questions_count?, missing_ai_key_count?,
 *  classroom?: {id, name},
 *  subject?: {id, code, name}, google_link?: {course_work_id, alternate_link,
 *  drive_file_id, has_blank_worksheet, posted_at, origin, can_push_grades,
 *  materials, last_synced_at}|null, questions?: [...],
 *  created_by, created_at, updated_at}
 *
 * missing_ai_key_count (detail only): answers waiting as `manual` because no
 * Gemini key was usable (DESIGN §13 banner; POST .../requeue-missing-key).
 *
 * mode worksheet|freeform, key_origin teacher|document|ai_draft|null
 * (ai_draft: the app labels the key "AI ร่าง ไม่มีคำตอบของครู"),
 * key_approved_at null = nothing is graded yet (DESIGN §19.5).
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
            'mode' => $this->mode,
            'source' => $this->source,
            'accept_late' => (bool) $this->accept_late,
            'score_only' => (bool) $this->score_only,
            'key_origin' => $this->key_origin,
            'key_approved_at' => $this->key_approved_at?->toIso8601String(),
            'questions_count' => $this->whenCounted('questions'),
            'missing_ai_key_count' => $this->whenCounted('missing_ai_key_count'),
            'classroom' => $this->whenLoaded('classroom', fn () => [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
            ]),
            // null for a Classroom website mirror until its key is approved (DESIGN §19.3).
            'subject' => $this->whenLoaded('subject', fn () => $this->subject === null ? null : [
                'id' => $this->subject->id,
                'code' => $this->subject->code,
                'name' => $this->subject->name,
            ]),
            // DESIGN §18.4, §19.8 assignment_google_links (AssignmentGoogleLink::toApi) | null
            'google_link' => $this->whenLoaded('googleLink', fn () => $this->googleLink?->toApi()),
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
