<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One roster row: {student_id, student_number, name, student_code, status, left_course_at,
 * pin_pending} (left_course_at: the Google account left the linked course;
 * pin_pending: added by the background roster sync, PIN never shown; DESIGN
 * §19.2; both null for a subject teacher, §24.8). The student is a
 * User loaded through Classroom::students(), so student_number is on the pivot.
 *
 * @mixin User
 */
class RosterStudentResource extends JsonResource
{
    /** Request attribute: the caller is a subject teacher, who gets no PIN or Google state (DESIGN §24.8). */
    public const LIMITED = 'roster_limited';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $limited = (bool) $request->attributes->get(self::LIMITED, false);

        return [
            'student_id' => $this->id,
            'student_number' => (int) $this->pivot->student_number,
            'name' => $this->name,
            'student_code' => $this->student_code,
            'status' => $this->status,
            'left_course_at' => $limited ? null : $this->pivot->left_course_at?->toIso8601String(),
            'pin_pending' => $limited ? null : $this->pivot->pin_pending_at !== null,
        ];
    }
}
