<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §24.3 C `user_google_identities`: the Google account a user signs
 * in with (§24.9). One per user and one user per Google account. Holds only
 * what §24.14 allows: the account id (`sub`), the verified e-mail, the name
 * and the picture URL, never a token.
 *
 * @property int $id
 * @property int $user_id
 * @property string $google_sub
 * @property string $email
 * @property string|null $name
 * @property string|null $picture_url
 * @property string $linked_via teacher_email|registration|self|pin_confirm|classroom_roster
 * @property int|null $linked_by
 * @property string|null $notice_version
 * @property Carbon $linked_at
 * @property Carbon|null $last_login_at
 */
class UserGoogleIdentity extends Model
{
    public const VIA_TEACHER_EMAIL = 'teacher_email';

    public const VIA_REGISTRATION = 'registration';

    public const VIA_SELF = 'self';

    public const VIA_PIN_CONFIRM = 'pin_confirm';

    public const VIA_CLASSROOM_ROSTER = 'classroom_roster';

    protected $fillable = [
        'user_id',
        'google_sub',
        'email',
        'name',
        'picture_url',
        'linked_via',
        'linked_by',
        'notice_version',
        'linked_at',
        'last_login_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
