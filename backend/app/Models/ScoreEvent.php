<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.4 `score_events`: append-only log of every score change (the data
 * behind the AI-bias analysis of §13). No updated_at.
 *
 * @property int $id
 * @property int $response_id
 * @property string $actor ai|teacher|system
 * @property int|null $actor_user_id
 * @property string $action ai_scored|override|bulk_approve|appeal_accepted|appeal_rejected|rescan
 * @property float|null $old_score
 * @property float|null $new_score
 * @property string|null $old_understanding
 * @property string|null $new_understanding
 * @property string|null $reason
 * @property Carbon $created_at
 */
class ScoreEvent extends Model
{
    public const UPDATED_AT = null;

    public const ACTOR_AI = 'ai';

    public const ACTOR_TEACHER = 'teacher';

    public const ACTOR_SYSTEM = 'system';

    public const ACTION_AI_SCORED = 'ai_scored';

    public const ACTION_OVERRIDE = 'override';

    public const ACTION_BULK_APPROVE = 'bulk_approve';

    public const ACTION_APPEAL_ACCEPTED = 'appeal_accepted';

    public const ACTION_APPEAL_REJECTED = 'appeal_rejected';

    public const ACTION_RESCAN = 'rescan';

    protected $fillable = [
        'response_id',
        'actor',
        'actor_user_id',
        'action',
        'old_score',
        'new_score',
        'old_understanding',
        'new_understanding',
        'reason',
    ];

    /** Actions that change a question's score (the others only confirm it). */
    public const SCORE_CHANGING_ACTIONS = [self::ACTION_OVERRIDE, self::ACTION_APPEAL_ACCEPTED, self::ACTION_RESCAN, self::ACTION_AI_SCORED];

    /**
     * A per-question score changed after the teacher took the total from
     * Classroom (DESIGN §19.3 "ล้าง total_override"): the total is the sum of
     * the questions again, in the same transaction as the change. This event
     * is the record of that change.
     */
    protected static function booted(): void
    {
        static::created(function (ScoreEvent $event) {
            if (! in_array($event->action, self::SCORE_CHANGING_ACTIONS, true)) {
                return;
            }
            $changed = in_array($event->action, [self::ACTION_RESCAN, self::ACTION_AI_SCORED], true)
                || $event->old_score !== $event->new_score;
            if (! $changed) {
                return;
            }
            Submission::query()
                ->whereIn('id', Response::query()->whereKey($event->response_id)->select('submission_id'))
                ->whereNotNull('total_override')
                ->update(['total_override' => null, 'updated_at' => now()]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_score' => 'float',
            'new_score' => 'float',
        ];
    }

    /** @return BelongsTo<Response, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
