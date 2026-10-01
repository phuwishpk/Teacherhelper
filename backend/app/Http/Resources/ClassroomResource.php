<?php

namespace App\Http\Resources;

use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, name, grade_level, academic_year, class_code, students_count,
 *  google_link?: {course_id, course_name, linked_at, roster_synced_at,
 *  work_synced_at}|null, auto_share_analysis, closed_at, my_role, created_at, updated_at}
 *
 * @mixin Classroom
 */
class ClassroomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'grade_level' => $this->grade_level,
            'academic_year' => $this->academic_year,
            'class_code' => $this->class_code,
            'students_count' => $this->whenCounted('students'),
            // DESIGN §18.4, §19.8 classroom_google_links (ClassroomGoogleLink::toApi) | null
            'google_link' => $this->whenLoaded('googleLink', fn () => $this->googleLink?->toApi()),
            // DESIGN §20.5: new analysis texts reach students without approval.
            'auto_share_analysis' => (bool) $this->auto_share_analysis,
            // DESIGN §24.6: closed = "ห้องเก่า", read-only.
            'closed_at' => $this->closed_at?->toIso8601String(),
            // DESIGN §24.8: homeroom | subject (subject teachers arrive with build 2).
            'my_role' => $request->user()?->id === $this->teacher_id ? 'homeroom' : 'subject',
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
