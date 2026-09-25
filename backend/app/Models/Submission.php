<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.4 `submissions`: a student's work on one assignment, created by
 * the first scan of any of its pages.
 *
 * status (App\Domain\Scans\SubmissionStatus derives it from the responses):
 *   awaiting_scan -> grading (some response still queued/extracted/failed)
 *   -> needs_review (every response scored or manual) -> reviewed (every
 *   response reviewed by the teacher) -> published (students see it).
 * A confirmed rescan of a published page reopens it (published_at cleared).
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $student_id
 * @property string $status
 * @property float|null $total_score
 * @property Carbon|null $published_at
 * @property int|null $published_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Submission extends Model
{
    public const STATUS_AWAITING_SCAN = 'awaiting_scan';

    public const STATUS_GRADING = 'grading';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'assignment_id',
        'student_id',
        'status',
        'total_score',
        'published_at',
        'published_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_AWAITING_SCAN,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_score' => 'float',
            'published_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return HasMany<Scan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    /** @return HasMany<Response, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(Response::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
