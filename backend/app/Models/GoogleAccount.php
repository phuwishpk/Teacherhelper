<?php

namespace App\Models;

use App\Domain\Google\GoogleScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §18.4 `google_accounts`: the Google account a teacher connected.
 * `encrypted_refresh_token` is transparently encrypted with APP_KEY and never
 * serialised; no API response, log line or job payload carries it.
 *
 * last_error holds why the account must be connected again (invalid_grant,
 * scope_missing); null while it works. An account whose stored scopes lack
 * one GoogleScopes::REQUIRED added later (classroom.announcements, DESIGN
 * §19.7) needs a reconnect too; the migration that added the scope marked
 * such rows scope_missing, so queries on last_error agree.
 *
 * @property int $user_id
 * @property string $google_sub
 * @property string $email
 * @property string $encrypted_refresh_token
 * @property string $scopes space-separated
 * @property Carbon $connected_at
 * @property string|null $last_error
 * @property Carbon|null $reconnect_notified_at the FCM of the current drop went out (DESIGN §19.3)
 * @property Carbon|null $updated_at
 */
class GoogleAccount extends Model
{
    public const CREATED_AT = null;

    /** The refresh token was revoked or expired (7 days in Testing mode, §18.5). */
    public const ERROR_INVALID_GRANT = 'invalid_grant';

    /** Google answered "insufficient authentication scopes": a scope was taken back. */
    public const ERROR_SCOPE_MISSING = 'scope_missing';

    /** DESIGN §19.7: an account connected before the announcements scope was added. */
    public const ANNOUNCEMENTS_RECONNECT_MESSAGE = 'ต้องเชื่อมบัญชี Google ใหม่ เพื่อให้แอปส่งผลตรวจเป็นประกาศส่วนตัวถึงนักเรียนได้';

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
        'reconnect_notified_at',
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
            'reconnect_notified_at' => 'datetime',
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
        return $this->last_error !== null || GoogleScopes::missing($this->scopeList()) !== [];
    }

    /** Only the announcements scope (DESIGN §19.7) is missing: the "เชื่อมใหม่เพื่อส่งประกาศ" case. */
    public function lacksAnnouncementsScope(): bool
    {
        return ! in_array(GoogleScopes::ANNOUNCEMENTS, $this->scopeList(), true);
    }

    /**
     * Why the teacher must connect again, in Thai (GET /google/status
     * `reconnect_message`, the 409 google_reconnect_required message); null
     * while the account works.
     */
    public function reconnectMessage(): ?string
    {
        if (! $this->needsReconnect()) {
            return null;
        }
        if ($this->last_error === self::ERROR_INVALID_GRANT) {
            return 'สิทธิ์ที่ให้ Google ไว้หมดอายุหรือถูกยกเลิกแล้ว ไปที่ ตั้งค่า → Google Classroom แล้วกด "เชื่อมใหม่"';
        }
        if ($this->lacksAnnouncementsScope()) {
            return self::ANNOUNCEMENTS_RECONNECT_MESSAGE;
        }

        return 'บัญชี Google ไม่ได้ให้สิทธิ์ครบตามที่แอปต้องใช้ ไปที่ ตั้งค่า → Google Classroom แล้วกด "เชื่อมใหม่" และติ๊กอนุญาตทุกข้อ';
    }
}
