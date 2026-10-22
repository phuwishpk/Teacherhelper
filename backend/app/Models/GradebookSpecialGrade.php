<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * DESIGN §23.6 `gradebook_special_grades`: ร (`r`) or มส (`ms`) the teacher
 * set for one student of a classroom in a course; it replaces the numeric
 * grade everywhere. The composite key (course, classroom, student) is
 * written through the query builder (GradebookSpecialGrades).
 *
 * @property int $course_id
 * @property int $classroom_id
 * @property int $student_id
 * @property string $special r|ms
 * @property string|null $note never sent to the student (§23.12)
 * @property int $set_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GradebookSpecialGrade extends Model
{
    public const SPECIALS = ['r', 'ms'];

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = ['course_id', 'classroom_id', 'student_id', 'special', 'note', 'set_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'classroom_id' => 'integer',
            'student_id' => 'integer',
            'set_by' => 'integer',
        ];
    }
}
