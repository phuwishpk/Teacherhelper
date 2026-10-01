<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One roster row: {student_id, student_number, name, student_code, status, left_course_at,
 * pin_pending} (left_course_at: the Google account left the linked course;
 * pin_pending: added by the background roster sync, PIN never shown; DESIGN
 * §19.2). The student is a
 * User loaded through Classroom::students(), so student_number is on the pivot.
 *
 * @mixin User
 */
class RosterStudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'student_id' => $this->id,
            'student_number' => (int) $this->pivot->student_number,
            'name' => $this->name,
            'student_code' => $this->student_code,
            'status' => $this->status,
            'left_course_at' => $this->pivot->left_course_at?->toIso8601String(),
            'pin_pending' => $this->pivot->pin_pending_at !== null,
        ];
    }
}
