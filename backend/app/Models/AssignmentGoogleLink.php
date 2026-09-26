<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §18.4 `assignment_google_links`: the Classroom courseWork an
 * assignment was posted as. Grades can be sent back only to courseWork this
 * project created (§18.2), which is why posting goes through the app.
 *
 * @property int $assignment_id
 * @property string $course_work_id
 * @property string $alternate_link
 * @property string|null $drive_file_id
 * @property int $posted_by
 * @property Carbon $posted_at
 */
class AssignmentGoogleLink extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'assignment_id';

    public $incrementing = false;

    protected $fillable = [
        'assignment_id',
        'course_work_id',
        'alternate_link',
        'drive_file_id',
        'posted_by',
        'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * `google_link` of an assignment and the answer of POST .../google-post.
     *
     * @return array{course_work_id: string, alternate_link: string, drive_file_id: string|null, has_blank_worksheet: bool, posted_at: string|null}
     */
    public function toApi(): array
    {
        return [
            'course_work_id' => $this->course_work_id,
            'alternate_link' => $this->alternate_link,
            'drive_file_id' => $this->drive_file_id,
            'has_blank_worksheet' => $this->drive_file_id !== null,
            'posted_at' => $this->posted_at?->toIso8601String(),
        ];
    }
}
