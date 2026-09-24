<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One roster row: {student_id, student_number, name, status}. The student is a
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
            'status' => $this->status,
        ];
    }
}
