<?php

namespace App\Http\Resources;

use App\Models\Classroom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, name, grade_level, academic_year, class_code, students_count,
 *  google_link?: {course_id, course_name, linked_at}|null, created_at, updated_at}
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
            // DESIGN §18.4 classroom_google_links: {course_id, course_name, linked_at} | null
            'google_link' => $this->whenLoaded('googleLink', fn () => $this->googleLink?->toApi()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
