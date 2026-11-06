<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DESIGN §29.12 `timetable_slots`: a course taught in a classroom on a
 * weekday, in a period.
 *
 * @property int $id
 * @property int $course_id
 * @property int $classroom_id
 * @property int $weekday
 * @property int|null $period_no
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property int $created_by
 */
class TimetableSlot extends Model
{
    protected $fillable = ['course_id', 'classroom_id', 'weekday', 'period_no', 'starts_at', 'ends_at', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['course_id' => 'integer', 'classroom_id' => 'integer', 'weekday' => 'integer', 'period_no' => 'integer', 'created_by' => 'integer'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return HasMany<TimetableCancellation, $this> */
    public function cancellations(): HasMany
    {
        return $this->hasMany(TimetableCancellation::class);
    }
}
