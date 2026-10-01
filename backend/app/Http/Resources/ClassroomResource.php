<?php

namespace App\Http\Resources;

use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, name, grade_level, academic_year, class_code, students_count,
 *  google_link?: {id, course_id, course_name, owner_user_id, app_course_id,
 *  linked_at, roster_synced_at, work_synced_at}|null (the viewer's own course),
 *  google_links?: [the same + owner: {id, name}, mine] (every course of the
 *  classroom for its homeroom teacher, the own one for a subject teacher),
 *  auto_share_analysis, closed_at, my_role: homeroom|subject,
 *  homeroom_teacher?: {id, name}, created_at, updated_at}
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
            // DESIGN §18.4, §19.8, §24.10 classroom_google_links (ClassroomGoogleLink::toApi):
            // google_link is the viewer's own course | null (one per teacher since build 4).
            'google_link' => $this->whenLoaded('googleLinks', fn () => $this->googleLinks
                ->first(fn (ClassroomGoogleLink $link) => $link->owner_user_id === $request->user()?->id)?->toApi()),
            'google_links' => $this->whenLoaded('googleLinks', fn () => $this->googleLinks
                ->filter(fn (ClassroomGoogleLink $link) => $request->user()?->id === $this->teacher_id || $link->owner_user_id === $request->user()?->id)
                ->map(fn (ClassroomGoogleLink $link) => [
                    ...$link->toApi(),
                    'owner' => $link->relationLoaded('owner') && $link->owner !== null ? ['id' => $link->owner->id, 'name' => $link->owner->name] : null,
                    'mine' => $link->owner_user_id === $request->user()?->id,
                ])->values()->all()),
            // DESIGN §20.5: new analysis texts reach students without approval.
            'auto_share_analysis' => (bool) $this->auto_share_analysis,
            // DESIGN §24.6: closed = "ห้องเก่า", read-only.
            'closed_at' => $this->closed_at?->toIso8601String(),
            // DESIGN §24.8: homeroom | subject (an own course is bound to another teacher's classroom).
            'my_role' => $request->user()?->id === $this->teacher_id ? 'homeroom' : 'subject',
            'homeroom_teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
