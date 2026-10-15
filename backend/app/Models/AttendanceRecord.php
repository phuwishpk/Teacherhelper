<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §29.5 `attendance_records`: a student's status in one session.
 *
 * @property int $id
 * @property int $attendance_session_id
 * @property int $student_id
 * @property string $status present|late|absent|personal_leave|sick_leave
 * @property string|null $note
 * @property int $updated_by
 */
class AttendanceRecord extends Model
{
    public const PRESENT = 'present';

    public const LATE = 'late';

    public const ABSENT = 'absent';

    public const PERSONAL_LEAVE = 'personal_leave';

    public const SICK_LEAVE = 'sick_leave';

    /** @var list<string> */
    public const STATUSES = [self::PRESENT, self::LATE, self::ABSENT, self::PERSONAL_LEAVE, self::SICK_LEAVE];

    /** The statuses that count toward the score; a leave is left out of it. */
    public const COUNTED = [self::PRESENT, self::LATE, self::ABSENT];

    protected $fillable = ['attendance_session_id', 'student_id', 'status', 'note', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_session_id' => 'integer',
            'student_id' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<AttendanceSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }
}
