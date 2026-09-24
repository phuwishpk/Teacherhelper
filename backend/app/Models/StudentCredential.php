<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.1 `student_credentials`: QR-card token hash + PIN hash + lockout state.
 *
 * @property int $student_id
 * @property string $qr_token_hash
 * @property Carbon $qr_issued_at
 * @property string $pin_hash
 * @property int $failed_pin_attempts
 * @property Carbon|null $locked_until
 */
class StudentCredential extends Model
{
    protected $primaryKey = 'student_id';

    public $incrementing = false;

    protected $fillable = [
        'student_id',
        'qr_token_hash',
        'qr_issued_at',
        'pin_hash',
        'failed_pin_attempts',
        'locked_until',
    ];

    protected $hidden = [
        'qr_token_hash',
        'pin_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qr_issued_at' => 'datetime',
            'locked_until' => 'datetime',
            'failed_pin_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
