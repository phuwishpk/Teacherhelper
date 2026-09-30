<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §23.7 `gradebook_published_grades`: one student's row of a
 * publication (composite key publication_id + student_id, insert only).
 *
 * @property int $publication_id
 * @property int $student_id
 * @property list<array<string, mixed>> $breakdown [{category_id, name, weight, percent, points, items: [{name, score, max, percent, state, dropped}]}]
 * @property float|null $total NULL: no value in any category (everything excused)
 * @property int|null $total_rounded
 * @property float|null $grade NULL with ร/มส or without a total
 * @property string|null $special r|ms
 * @property bool $attendance_warning
 */
class GradebookPublishedGrade extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['publication_id', 'student_id', 'breakdown', 'total', 'total_rounded', 'grade', 'special', 'attendance_warning'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'publication_id' => 'integer',
            'student_id' => 'integer',
            'breakdown' => 'array',
            'total' => 'float',
            'total_rounded' => 'integer',
            'grade' => 'float',
            'attendance_warning' => 'boolean',
        ];
    }

    /** @return BelongsTo<GradebookPublication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(GradebookPublication::class, 'publication_id');
    }
}
