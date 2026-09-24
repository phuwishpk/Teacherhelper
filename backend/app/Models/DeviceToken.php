<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.1 `device_tokens`: one FCM registration token per app install.
 *
 * @property int $id
 * @property int $user_id
 * @property string $fcm_token
 * @property Carbon $last_seen_at
 */
class DeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'fcm_token',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
