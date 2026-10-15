<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §32 `google_events`: one sign-in, link or Classroom connection
 * with Google that did not simply succeed (and the links, unlinks and
 * connections themselves), for the admin's "ปัญหาการเชื่อม Google".
 *
 * @property int $id
 * @property int|null $user_id the account it concerns, null when the Google account is linked to nobody
 * @property string $flow signin|classroom
 * @property string $event what was attempted (login, link, signup, web_callback, connect, ...)
 * @property string $result ok or the error code
 * @property string|null $detail the path or Google's own error (unknown_student, access_denied, ...)
 * @property string|null $email the Google e-mail when known (kept 30 days)
 * @property Carbon $created_at
 */
class GoogleEvent extends Model
{
    public const FLOW_SIGNIN = 'signin';

    public const FLOW_CLASSROOM = 'classroom';

    /** Rows older than this are deleted by eduvision:purge-images. */
    public const RETENTION_DAYS = 30;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'flow', 'event', 'result', 'detail', 'email'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
