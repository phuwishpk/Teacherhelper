<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §20.6 `lesson_plans`: a lesson plan of a course (แผนการจัดการ
 * เรียนรู้, optional), in a unit or not: objectives (จุดประสงค์), content
 * (สาระการเรียนรู้), activities (กิจกรรม), assessment (การวัดและประเมินผล)
 * and its indicators. taught_on marks it taught (chart 5, §20.4). position
 * is a gap-free 1..n per course.
 *
 * @property int $id
 * @property int $course_id
 * @property int|null $unit_id
 * @property int $position
 * @property string $title
 * @property int|null $hours
 * @property string|null $objectives
 * @property string|null $content
 * @property string|null $activities
 * @property string|null $assessment
 * @property Carbon|null $taught_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LessonPlan extends Model
{
    protected $fillable = [
        'course_id',
        'unit_id',
        'position',
        'title',
        'hours',
        'objectives',
        'content',
        'activities',
        'assessment',
        'taught_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'unit_id' => 'integer',
            'position' => 'integer',
            'hours' => 'integer',
            'taught_on' => 'date',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsToMany<Skill, $this> */
    public function indicators(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'lesson_plan_indicators');
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
