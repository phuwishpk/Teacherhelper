<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §20.5 / §20.6 `student_analyses`: one analysis per (student,
 * classroom).
 *
 * strengths / areas are written by code (StudentAnalyses::record) as
 * [{skill_id, value, n_obs}] together with computed_input_hash, the hash
 * of the mastery input. generated_input_hash is the hash of the input the
 * current texts were written from (only when Gemini succeeded), so a row
 * whose two hashes differ, or that has no text yet, needs new texts.
 * queued_input_hash is the input sent in the batch still pending.
 *
 * student_text is Gemini's (or the teacher's edited) draft for the
 * student; shared_student_text is what the student sees, set by approval
 * or by the classroom's auto-share.
 *
 * @property int $id
 * @property int $student_id
 * @property int $classroom_id
 * @property string $computed_input_hash
 * @property string|null $queued_input_hash
 * @property string|null $generated_input_hash
 * @property list<array{skill_id: int, value: float, n_obs: int}> $strengths
 * @property list<array{skill_id: int, value: float, n_obs: int}> $areas
 * @property string $status computed|queued|drafted|failed
 * @property string|null $teacher_text
 * @property string|null $student_text
 * @property list<int>|null $next_step_skill_ids
 * @property string|null $generated_via batch|now
 * @property int|null $batch_id
 * @property Carbon|null $generated_at
 * @property string|null $shared_student_text
 * @property Carbon|null $shared_at
 * @property int|null $approved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StudentAnalysis extends Model
{
    public const STATUS_COMPUTED = 'computed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DRAFTED = 'drafted';

    public const STATUS_FAILED = 'failed';

    public const VIA_BATCH = 'batch';

    public const VIA_NOW = 'now';

    protected $table = 'student_analyses';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'classroom_id' => 'integer',
            'strengths' => 'array',
            'areas' => 'array',
            'next_step_skill_ids' => 'array',
            'batch_id' => 'integer',
            'generated_at' => 'datetime',
            'shared_at' => 'datetime',
            'approved_by' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<AnalysisBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(AnalysisBatch::class, 'batch_id');
    }

    /** Texts exist but were written from older mastery than the current one. */
    public function isStale(): bool
    {
        return $this->generated_input_hash !== null && $this->generated_input_hash !== $this->computed_input_hash;
    }

    /** The current student draft is not what the student sees yet. */
    public function awaitsApproval(): bool
    {
        return $this->student_text !== null && $this->student_text !== $this->shared_student_text;
    }
}
