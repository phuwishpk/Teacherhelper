<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §23.2 `gradebook_categories`: a weighted score category of a
 * course (the weights of a course sum to 100.00, checked in
 * GradebookSettings). At most one category per course is the default of
 * new homework.
 *
 * @property int $id
 * @property int $course_id
 * @property int $position
 * @property string $name
 * @property float $weight percent of the course total
 * @property int $drop_lowest 0–5 lowest item percentages left out per student
 * @property bool $is_homework_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GradebookCategory extends Model
{
    public const MAX_DROP_LOWEST = 5;

    public const MAX_CATEGORIES = 10;

    protected $fillable = ['course_id', 'position', 'name', 'weight', 'drop_lowest', 'is_homework_default'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'position' => 'integer',
            'weight' => 'float',
            'drop_lowest' => 'integer',
            'is_homework_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /** @return HasMany<GradebookItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GradebookItem::class, 'category_id');
    }
}
