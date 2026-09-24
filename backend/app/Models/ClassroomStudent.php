<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * DESIGN §8.1 `classroom_students` pivot (composite key classroom_id + student_id).
 *
 * @property int $classroom_id
 * @property int $student_id
 * @property int $student_number
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
        ];
    }
}
