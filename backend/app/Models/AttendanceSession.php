<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §29.5 `attendance_sessions`: one checked period of a course in a
 * classroom.
 *
 * @property int $id
 * @property int $course_id
 * @property int $classroom_id
 * @property Carbon $held_on
 * @property int|null $period_no
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property string|null $note
 * @property int $created_by
 */
class AttendanceSession extends Model
{
    protected $fillable = ['course_id', 'classroom_id', 'held_on', 'period_no', 'starts_at', 'ends_at', 'note', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'classroom_id' => 'integer',
            'held_on' => 'date:Y-m-d',
            'period_no' => 'integer',
            'created_by' => 'integer',
        ];
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

    /** @return HasMany<AttendanceRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
