<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.1 `teacher_api_keys`: the teacher's own Gemini key. `encrypted_key`
 * is transparently encrypted with APP_KEY and never serialised (§10.1).
 *
 * @property int $user_id
 * @property string $provider
 * @property string $encrypted_key
 * @property string $key_last4
 * @property Carbon|null $last_verified_at
 */
class TeacherApiKey extends Model
{
    public const PROVIDER_GEMINI = 'gemini';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'provider',
        'encrypted_key',
        'key_last4',
        'last_verified_at',
    ];

    protected $hidden = [
        'encrypted_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encrypted_key' => 'encrypted',
            'last_verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
