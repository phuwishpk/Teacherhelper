<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §23.3 `gradebook_items`: a score item the teacher added to one
 * classroom's gradebook of a course (การแต่งกาย, ความตั้งใจ, การเข้าเรียน).
 * It counts once any score of the classroom was typed (§23.4).
 *
 * @property int $id
 * @property int $course_id
 * @property int $classroom_id
 * @property int|null $category_id NULL = "ยังไม่ระบุหมวด" (not counted)
 * @property string $name
 * @property float $max_points > 0
 * @property bool $is_attendance its scores feed the "อาจติด มส" warning
 * @property int $position
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GradebookItem extends Model
{
    protected $fillable = ['course_id', 'classroom_id', 'category_id', 'name', 'max_points', 'is_attendance', 'position', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'classroom_id' => 'integer',
            'category_id' => 'integer',
            'max_points' => 'float',
            'is_attendance' => 'boolean',
            'position' => 'integer',
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

    /** @return BelongsTo<GradebookCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(GradebookCategory::class, 'category_id');
    }

    /** @return HasMany<GradebookEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(GradebookEntry::class);
    }
}
