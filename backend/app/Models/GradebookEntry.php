<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * DESIGN §23.10 `gradebook_entries`: a typed score and the "ยกเว้น" flag of
 * one student on one gradebook item or one assignment (exactly one of the
 * two ids). An app-graded assignment never has a score here (its total
 * comes from the published submission); only `excused` is stored.
 *
 * @property int $id
 * @property int $classroom_id
 * @property int $student_id
 * @property int|null $assignment_id
 * @property int|null $gradebook_item_id
 * @property float|null $score
 * @property bool $excused
 * @property int $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GradebookEntry extends Model
{
    protected $fillable = ['classroom_id', 'student_id', 'assignment_id', 'gradebook_item_id', 'score', 'excused', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classroom_id' => 'integer',
            'student_id' => 'integer',
            'assignment_id' => 'integer',
            'gradebook_item_id' => 'integer',
            'score' => 'float',
            'excused' => 'boolean',
            'updated_by' => 'integer',
        ];
    }
}
