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
 * origin (DESIGN §19.3, §19.8): `app` (POST .../google-post) or
 * `classroom_web`: courseWork the teacher created on the Classroom website,
 * mirrored by the cron sync (ImportCourseWorkJob). Its hand-ins are read and
 * graded, but Classroom refuses grades and returns from this project
 * (ProjectPermissionDenied), so no grade is ever pushed for it. posted_by is
 * then the teacher whose account linked the course, posted_at the
 * courseWork's creationTime.
 *
 * @property int $assignment_id
 * @property string $course_work_id
 * @property string $alternate_link
 * @property string|null $drive_file_id
 * @property int $posted_by
 * @property Carbon $posted_at
 * @property string $origin app|classroom_web
 * @property list<array{drive_file_id: string, title: string, mime_type: string, supported: bool}>|null $materials
 * @property Carbon|null $last_synced_at the last sync of its submissions (the cron takes the oldest first)
 */
class AssignmentGoogleLink extends Model
{
    public const ORIGIN_APP = 'app';

    public const ORIGIN_CLASSROOM_WEB = 'classroom_web';

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
        'origin',
        'materials',
        'last_synced_at',
    ];

    protected $attributes = [
        'origin' => self::ORIGIN_APP,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'materials' => 'array',
            'last_synced_at' => 'datetime',
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

    /** Created on the Classroom website: the app cannot set its grades (DESIGN §19.3). */
    public function isFromClassroomWeb(): bool
    {
        return $this->origin === self::ORIGIN_CLASSROOM_WEB;
    }

    /**
     * `google_link` of an assignment and the answer of POST .../google-post.
     * can_push_grades = false for courseWork created on the Classroom
     * website (the app shows "เปิดใน Classroom" and "คัดลอกคะแนน" instead).
     *
     * @return array{course_work_id: string, alternate_link: string, drive_file_id: string|null, has_blank_worksheet: bool, posted_at: string|null, origin: string, can_push_grades: bool, materials: list<array<string, mixed>>, last_synced_at: string|null}
     */
    public function toApi(): array
    {
        return [
            'course_work_id' => $this->course_work_id,
            'alternate_link' => $this->alternate_link,
            'drive_file_id' => $this->drive_file_id,
            'has_blank_worksheet' => $this->drive_file_id !== null,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'origin' => $this->origin ?? self::ORIGIN_APP,
            'can_push_grades' => ! $this->isFromClassroomWeb(),
            'materials' => array_values($this->materials ?? []),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
