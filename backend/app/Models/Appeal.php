<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.4 `appeals`: a student's request to re-check one published
 * response, at most once per response (§13).
 *
 * @property int $id
 * @property int $response_id
 * @property int $student_id
 * @property string|null $reason
 * @property string $status open|accepted|rejected
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string|null $teacher_note
 */
class Appeal extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'response_id',
        'student_id',
        'reason',
        'status',
        'resolved_by',
        'resolved_at',
        'teacher_note',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Response, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
