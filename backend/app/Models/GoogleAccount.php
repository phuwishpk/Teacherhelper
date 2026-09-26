<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §18.4 `google_accounts`: the Google account a teacher connected.
 * `encrypted_refresh_token` is transparently encrypted with APP_KEY and never
 * serialised; no API response, log line or job payload carries it.
 *
 * last_error holds why the account must be connected again (invalid_grant,
 * scope_missing); null while it works.
 *
 * @property int $user_id
 * @property string $google_sub
 * @property string $email
 * @property string $encrypted_refresh_token
 * @property string $scopes space-separated
 * @property Carbon $connected_at
 * @property string|null $last_error
 * @property Carbon|null $updated_at
 */
class GoogleAccount extends Model
{
    public const CREATED_AT = null;

    /** The refresh token was revoked or expired (7 days in Testing mode, §18.5). */
    public const ERROR_INVALID_GRANT = 'invalid_grant';

    /** Google answered "insufficient authentication scopes": a scope was taken back. */
    public const ERROR_SCOPE_MISSING = 'scope_missing';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'google_sub',
        'email',
        'encrypted_refresh_token',
        'scopes',
        'connected_at',
        'last_error',
    ];

    protected $hidden = [
        'encrypted_refresh_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encrypted_refresh_token' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return list<string>
     */
    public function scopeList(): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($this->scopes)) ?: []));
    }

    public function needsReconnect(): bool
    {
        return $this->last_error !== null;
    }
}
