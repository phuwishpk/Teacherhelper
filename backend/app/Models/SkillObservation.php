<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.5 `skill_observations`: one score ratio of one student on one
 * skill, from a published answer (`homework`, response_id) or a practice
 * attempt (`practice`, practice_attempt_id). The EWMA mastery (§14.2) is a
 * pure function of these rows ordered by observed_at.
 *
 * @property int $id
 * @property int $student_id
 * @property int $skill_id
 * @property string $source homework|practice
 * @property int|null $response_id
 * @property int|null $practice_attempt_id
 * @property float $score_ratio
 * @property Carbon $observed_at
 */
class SkillObservation extends Model
{
    public const SOURCE_HOMEWORK = 'homework';

    public const SOURCE_PRACTICE = 'practice';

    protected $fillable = [
        'student_id',
        'skill_id',
        'source',
        'response_id',
        'practice_attempt_id',
        'score_ratio',
        'observed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score_ratio' => 'float',
            'observed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /** @return BelongsTo<Response, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }

    /** @return BelongsTo<PracticeAttempt, $this> */
    public function practiceAttempt(): BelongsTo
    {
        return $this->belongsTo(PracticeAttempt::class);
    }
}
