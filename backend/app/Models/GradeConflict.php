<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §19.3, §19.8 `grade_conflicts`: the total in Classroom differs from
 * the app's ("คะแนนไม่ตรงกัน"). The app's total is the real one; the teacher
 * resolves each row:
 *
 *   pushed_app          the app's effective total was sent to Classroom
 *                       again (courseWork the app posted only)
 *   accepted_classroom  Classroom's grade became submissions.total_override
 *                       (reason "รับคะแนนจาก Classroom"); this row is the
 *                       record of that submission-level override
 *   dismissed           nothing changed; app_score / classroom_score hold what
 *                       the teacher saw, and no new row opens while both
 *                       sides still have those values
 *
 * @property int $id
 * @property int $submission_id
 * @property int $import_id
 * @property float|null $app_score
 * @property float|null $classroom_score
 * @property string $status open|pushed_app|accepted_classroom|dismissed
 * @property string|null $reason
 * @property Carbon $detected_at
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 */
class GradeConflict extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_PUSHED_APP = 'pushed_app';

    public const STATUS_ACCEPTED_CLASSROOM = 'accepted_classroom';

    public const STATUS_DISMISSED = 'dismissed';

    public const ACCEPT_REASON = 'รับคะแนนจาก Classroom';

    public $timestamps = false;

    protected $fillable = [
        'submission_id',
        'import_id',
        'app_score',
        'classroom_score',
        'status',
        'reason',
        'detected_at',
        'resolved_by',
        'resolved_at',
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
            'app_score' => 'float',
            'classroom_score' => 'float',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<ClassroomSubmissionImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(ClassroomSubmissionImport::class, 'import_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
