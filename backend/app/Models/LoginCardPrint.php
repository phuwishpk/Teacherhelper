<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued render of QR login cards (see the migration for why this table
 * exists). Status: queued -> rendering -> ready | failed, like worksheet_prints.
 *
 * @property int $id
 * @property int $school_id
 * @property int|null $classroom_id
 * @property int|null $student_id
 * @property int $requested_by
 * @property string $status
 * @property string|null $file_path
 * @property string|null $error
 */
class LoginCardPrint extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RENDERING = 'rendering';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'school_id',
        'classroom_id',
        'student_id',
        'requested_by',
        'status',
        'file_path',
        'error',
    ];

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->file_path !== null;
    }
}
