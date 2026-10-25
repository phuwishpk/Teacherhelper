<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * DESIGN §37.8: the label ("S1") that stands for a student in one assistant
 * conversation, so Gemini never sees a name, a number or an id.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $label
 * @property int $student_id
 * @property int|null $classroom_id the classroom the teacher sees the student in
 * @property Carbon|null $created_at
 */
class AssistantStudentLabel extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'label', 'student_id', 'classroom_id'];

    protected function casts(): array
    {
        return ['student_id' => 'integer', 'classroom_id' => 'integer', 'created_at' => 'datetime'];
    }
}
