<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §23.7 `gradebook_publications`: one "ประกาศเกรด" of a classroom
 * in a course, with the categories and cutoffs used at the time. Students
 * see the latest one that was not withdrawn.
 *
 * @property int $id
 * @property int $course_id
 * @property int $classroom_id
 * @property list<array{id: int, name: string, weight: float, drop_lowest: int}> $categories
 * @property list<int> $cutoffs
 * @property int $published_by
 * @property Carbon $published_at
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GradebookPublication extends Model
{
    protected $fillable = ['course_id', 'classroom_id', 'categories', 'cutoffs', 'published_by', 'published_at', 'withdrawn_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'classroom_id' => 'integer',
            'categories' => 'array',
            'cutoffs' => 'array',
            'published_by' => 'integer',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<GradebookPublishedGrade, $this> */
    public function grades(): HasMany
    {
        return $this->hasMany(GradebookPublishedGrade::class, 'publication_id');
    }
}
