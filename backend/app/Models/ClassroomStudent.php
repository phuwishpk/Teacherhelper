<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.1 `classroom_students` pivot (composite key classroom_id + student_id).
 *
 * @property int $classroom_id
 * @property int $student_id
 * @property int $student_number
 * @property string|null $google_user_id the matched Google Classroom account (DESIGN §18.4)
 * @property string|null $google_email
 * @property Carbon|null $left_course_at the account left the linked course (DESIGN §19.2)
 */
class ClassroomStudent extends Pivot
{
    protected $table = 'classroom_students';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_number' => 'integer',
            'left_course_at' => 'datetime',
        ];
    }
}
