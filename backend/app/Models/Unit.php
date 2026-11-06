<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §20.6 `units`: a unit of a course (หน่วยการเรียนรู้, optional)
 * with its hours and indicators. position is a gap-free 1..n per course
 * (UNIQUE course_id + position, CoursePositions).
 *
 * @property int $id
 * @property int $course_id
 * @property int $position
 * @property string $title
 * @property int|null $hours
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Unit extends Model
{
    protected $fillable = [
        'course_id',
        'position',
        'title',
        'hours',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'position' => 'integer',
            'hours' => 'integer',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsToMany<Skill, $this> */
    public function indicators(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'unit_indicators');
    }

    /** @return HasMany<LessonPlan, $this> */
    public function lessonPlans(): HasMany
    {
        return $this->hasMany(LessonPlan::class)->orderBy('position');
    }
}
