<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.5 `practice_attempts`: a typed answer to a practice item and
 * its deterministic score (§14.1, AnswerMatcher). Append-only, no updated_at.
 *
 * @property int $id
 * @property int $practice_item_id
 * @property int $student_id
 * @property string $answer
 * @property float $score_ratio
 * @property Carbon $created_at
 */
class PracticeAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'practice_item_id',
        'student_id',
        'answer',
        'score_ratio',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score_ratio' => 'float',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PracticeItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PracticeItem::class, 'practice_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
