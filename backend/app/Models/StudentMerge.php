<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §24.3 `student_merges`: the audit row of one merge of student
 * accounts (§24.5). `summary` holds, per table, the ids moved to the kept
 * account and the whole rows that were dropped; there is no undo.
 *
 * @property int $id
 * @property int $school_id
 * @property int $kept_student_id
 * @property int $merged_student_id
 * @property int $merged_by
 * @property array<string, mixed> $summary
 * @property Carbon $created_at
 */
class StudentMerge extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'kept_student_id',
        'merged_student_id',
        'merged_by',
        'summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function keptStudent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kept_student_id');
    }

    /** @return BelongsTo<User, $this> */
    public function mergedStudent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_student_id');
    }
}
