<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.4 `scans`: one uploaded worksheet page (POST /scans, §9.4).
 *
 * state: `active` is the page in use; `superseded` was replaced by a later
 * scan of the same (assignment, student, page); `pending_confirm` is a rescan
 * of a published submission that waits for the teacher's
 * POST /scans/{id}/confirm-replace. Its crops and readings wait on the
 * private disk (App\Domain\Scans\ScanFiles::pendingDirectory). A rescan the
 * teacher never confirms expires (App\Domain\Scans\ScanRetention): its files
 * are deleted and it becomes `superseded`, since it can no longer be used.
 *
 * @property int $id
 * @property string $client_scan_id
 * @property int $submission_id
 * @property int $page_no
 * @property int $layout_version
 * @property int $uploaded_by
 * @property Carbon $scanned_at
 * @property float $blur_score
 * @property string|null $page_image_path
 * @property string $state
 * @property string $source camera|classroom (DESIGN §18.4)
 * @property string|null $google_submission_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Scan extends Model
{
    public const STATE_ACTIVE = 'active';

    public const STATE_SUPERSEDED = 'superseded';

    public const STATE_PENDING_CONFIRM = 'pending_confirm';

    /** Taken with the phone camera (the default). */
    public const SOURCE_CAMERA = 'camera';

    /** Downloaded from a Google Classroom submission on the teacher's phone (DESIGN §18.2). */
    public const SOURCE_CLASSROOM = 'classroom';

    protected $attributes = [
        'source' => self::SOURCE_CAMERA,
    ];

    protected $fillable = [
        'client_scan_id',
        'submission_id',
        'page_no',
        'layout_version',
        'uploaded_by',
        'scanned_at',
        'blur_score',
        'page_image_path',
        'state',
        'source',
        'google_submission_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_no' => 'integer',
            'layout_version' => 'integer',
            'scanned_at' => 'datetime',
            'blur_score' => 'float',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<Response, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(Response::class);
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    public function isPendingConfirm(): bool
    {
        return $this->state === self::STATE_PENDING_CONFIRM;
    }

    public function isSuperseded(): bool
    {
        return $this->state === self::STATE_SUPERSEDED;
    }
}
