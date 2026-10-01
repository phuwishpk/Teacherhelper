<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §24.3 B `classroom_course_requests`: a request to bind a course to
 * another teacher's classroom (shared homerooms, §24.7). Approving it writes
 * course_classroom; an admin's direct binding is stored as an approved row
 * with origin = admin for the record.
 *
 * @property int $id
 * @property int $classroom_id
 * @property int $course_id
 * @property int $requested_by the course's creator, or the admin when origin = admin
 * @property string $origin teacher|classroom_import|admin
 * @property string $status pending|approved|declined|cancelled
 * @property string|null $message
 * @property string|null $google_course_id
 * @property string|null $google_course_name
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decline_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClassroomCourseRequest extends Model
{
    public const ORIGIN_TEACHER = 'teacher';

    public const ORIGIN_CLASSROOM_IMPORT = 'classroom_import';

    public const ORIGIN_ADMIN = 'admin';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_DECLINED, self::STATUS_CANCELLED];

    protected $fillable = [
        'classroom_id',
        'course_id',
        'requested_by',
        'origin',
        'status',
        'message',
        'google_course_id',
        'google_course_name',
        'decided_by',
        'decided_at',
        'decline_reason',
    ];

    protected $attributes = [
        'origin' => self::ORIGIN_TEACHER,
        'status' => self::STATUS_PENDING,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classroom_id' => 'integer',
            'course_id' => 'integer',
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
