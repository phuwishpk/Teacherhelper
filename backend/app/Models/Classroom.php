<?php

namespace App\Models;

use Database\Factories\ClassroomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * DESIGN §8.1 `classrooms`.
 *
 * @property int $id
 * @property int $school_id
 * @property int $teacher_id
 * @property string $name
 * @property int $grade_level
 * @property int $academic_year
 * @property string $class_code
 */
class Classroom extends Model
{
    /** @use HasFactory<ClassroomFactory> */
    use HasFactory;

    protected $fillable = [
        'school_id',
        'teacher_id',
        'name',
        'grade_level',
        'academic_year',
        'class_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade_level' => 'integer',
            'academic_year' => 'integer',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Students of this classroom with their student_number (เลขที่) on the pivot.
     *
     * @return BelongsToMany<User, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classroom_students', 'classroom_id', 'student_id')
            ->using(ClassroomStudent::class)
            ->withPivot('student_number')
            ->withTimestamps()
            ->orderByPivot('student_number');
    }
}
