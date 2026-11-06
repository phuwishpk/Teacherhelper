<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.5 `practice_items`: one remedial question of the school's bank,
 * per skill (§14.1). Shapes (same as questions.answer_key, §8.3):
 *
 *   numeric  options null,            answer_key {"accepted": [..], "numeric": {"value", "abs_tol"}}
 *   short    options null,            answer_key {"accepted": [..]}
 *   mcq      options [{key, text}..], answer_key {"correct": "B"}
 *
 * @property int $id
 * @property int $school_id
 * @property int $skill_id
 * @property string $answer_type numeric|short|mcq
 * @property string $prompt_text
 * @property list<array{key: string, text: string}>|null $options
 * @property array<string, mixed> $answer_key
 * @property string $explanation
 * @property string $status draft|approved|retired
 * @property string $source ai|teacher
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PracticeItem extends Model
{
    public const TYPE_NUMERIC = 'numeric';

    public const TYPE_SHORT = 'short';

    public const TYPE_MCQ = 'mcq';

    public const TYPES = [self::TYPE_NUMERIC, self::TYPE_SHORT, self::TYPE_MCQ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_RETIRED];

    public const SOURCE_AI = 'ai';

    public const SOURCE_TEACHER = 'teacher';

    protected $fillable = [
        'school_id',
        'skill_id',
        'answer_type',
        'prompt_text',
        'options',
        'answer_key',
        'explanation',
        'status',
        'source',
        'approved_by',
        'approved_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'answer_key' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<PracticeAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PracticeAttempt::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * @param  Builder<PracticeItem>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', self::STATUS_APPROVED);
    }
}
