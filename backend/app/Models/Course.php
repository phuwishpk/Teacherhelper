<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §20.1 / §20.6 `courses`: a teacher's course (รายวิชา), created
 * once and bound to any number of their classrooms (course_classroom). It
 * holds its indicators (course_indicators), optional units and lesson
 * plans; every new assignment belongs to a course of its classroom.
 * Only its creator sees and changes it (§20.9).
 *
 * @property int $id
 * @property int $school_id
 * @property int $created_by
 * @property int $subject_id
 * @property string $code
 * @property string $name
 * @property int $grade_level
 * @property int $semester 1, 2 or 0 = the whole year
 * @property int $academic_year Buddhist year (พ.ศ.)
 * @property int|null $hours
 * @property string|null $description
 * @property list<int>|null $grade_cutoffs the 7 minimums of grades 4 … 1 (DESIGN §23.2); NULL = the default
 * @property string|null $gradebook_template the template the gradebook categories started from
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Course extends Model
{
    public const SEMESTERS = [0, 1, 2];

    protected $fillable = [
        'school_id',
        'created_by',
        'subject_id',
        'code',
        'name',
        'grade_level',
        'semester',
        'academic_year',
        'hours',
        'description',
        'grade_cutoffs',
        'gradebook_template',
    ];

    protected $attributes = [
        'semester' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'created_by' => 'integer',
            'subject_id' => 'integer',
            'grade_level' => 'integer',
            'semester' => 'integer',
            'academic_year' => 'integer',
            'hours' => 'integer',
            'grade_cutoffs' => 'array',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsToMany<Classroom, $this> */
    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'course_classroom');
    }

    /** @return BelongsToMany<Skill, $this> */
    public function indicators(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'course_indicators');
    }

    /** @return HasMany<Unit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->orderBy('position');
    }

    /** @return HasMany<LessonPlan, $this> */
    public function lessonPlans(): HasMany
    {
        return $this->hasMany(LessonPlan::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /** The weighted gradebook categories in order (DESIGN §23.2). @return HasMany<GradebookCategory, $this> */
    public function gradebookCategories(): HasMany
    {
        return $this->hasMany(GradebookCategory::class)->orderBy('position');
    }
}
