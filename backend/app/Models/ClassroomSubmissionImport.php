<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §18.4 `classroom_submission_imports`: one Google Classroom
 * studentSubmission of a posted assignment and what EduVision did with it.
 *
 * @property int $id
 * @property int $assignment_id
 * @property string $google_submission_id
 * @property string $google_user_id
 * @property int|null $student_id
 * @property string $state
 * @property list<array{drive_file_id: string, title: string, mime_type: string}> $attachments
 * @property string $google_update_time
 * @property string|null $alternate_link
 * @property string|null $retake_reason
 * @property Carbon|null $grade_pushed_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClassroomSubmissionImport extends Model
{
    public const STATE_NEW = 'new';

    public const STATE_IMPORTED = 'imported';

    public const STATE_NEEDS_RETAKE = 'needs_retake';

    public const STATE_RETURNED_FOR_RETAKE = 'returned_for_retake';

    public const STATE_GRADED = 'graded';

    public const STATE_GRADE_FAILED = 'grade_failed';

    /** States the teacher may still send back for a new photo (§18.2). */
    public const RETURNABLE_STATES = [self::STATE_NEW, self::STATE_IMPORTED, self::STATE_NEEDS_RETAKE];

    protected $fillable = [
        'assignment_id',
        'google_submission_id',
        'google_user_id',
        'student_id',
        'state',
        'attachments',
        'google_update_time',
        'alternate_link',
        'retake_reason',
        'grade_pushed_at',
        'last_error',
    ];

    protected $attributes = [
        'state' => self::STATE_NEW,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'grade_pushed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function canReturnForRetake(): bool
    {
        return in_array($this->state, self::RETURNABLE_STATES, true);
    }
}
