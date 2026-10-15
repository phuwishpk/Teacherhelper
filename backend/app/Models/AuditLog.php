<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §29.14 `audit_logs`: one row per admin action on behalf of a user.
 * Append-only: rows are never updated or deleted.
 *
 * @property int $id
 * @property int $actor_id
 * @property string $action
 * @property int|null $target_user_id
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 * @property-read User $actor
 * @property-read User|null $target
 */
class AuditLog extends Model
{
    public const IMPERSONATE_START = 'impersonate.start';

    public const IMPERSONATE_STOP = 'impersonate.stop';

    /** An admin deleted a teacher or student account (DESIGN §31); meta.purge_at. */
    public const ACCOUNT_DELETE = 'account.delete';

    public const ACCOUNT_RESTORE = 'account.restore';

    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'target_user_id', 'meta', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @param  array<string, mixed>  $meta */
    public static function record(User $actor, string $action, ?User $target = null, array $meta = []): self
    {
        return self::create([
            'actor_id' => $actor->id,
            'action' => $action,
            'target_user_id' => $target?->id,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
