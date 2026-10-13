<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §29.13.4 `classroom_teachers`: an invitation to co-teach a
 * classroom (`pending`), accepted into a co-teacher row that names the
 * teacher, or declined.
 *
 * @property int $id
 * @property int $classroom_id
 * @property string $email lower case
 * @property int|null $teacher_id the teacher who accepted
 * @property int $invited_by
 * @property string $status
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property-read Classroom $classroom
 * @property-read User|null $teacher
 * @property-read User $inviter
 */
class ClassroomTeacher extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'classroom_id',
        'email',
        'teacher_id',
        'invited_by',
        'status',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
