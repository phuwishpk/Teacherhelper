<?php

namespace App\Models;

use App\Domain\Worksheets\WorksheetFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §8.3 `worksheet_prints`: status queued -> rendering -> ready | failed.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $layout_version
 * @property int $requested_by
 * @property string $status
 * @property string|null $file_path
 * @property string|null $error
 */
class WorksheetPrint extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RENDERING = 'rendering';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'assignment_id',
        'layout_version',
        'requested_by',
        'status',
        'file_path',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
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

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_READY || $this->status === self::STATUS_FAILED;
    }

    /**
     * Terminal failure with a Thai message the app shows to the teacher; the
     * batch parts rendered so far are removed.
     */
    public function markFailed(string $message): void
    {
        $this->update(['status' => self::STATUS_FAILED, 'error' => mb_substr($message, 0, 255)]);
        WorksheetFiles::discardParts($this);
    }
}
