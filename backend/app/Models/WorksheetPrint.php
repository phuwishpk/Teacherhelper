<?php

namespace App\Models;

use App\Domain\Worksheets\WorksheetFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §8.3 `worksheet_prints`: status queued -> rendering -> ready | failed.
 * Also the exam prints of DESIGN §22.6 (`kind`): a question booklet of one
 * version (`version_no`, no layout), the answer sheets of a classroom and
 * the teacher's key sheet.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int|null $layout_version null for an exam booklet
 * @property string $kind worksheet|exam_booklet|answer_sheet|key_sheet
 * @property int|null $version_no the version of an exam booklet
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

    public const KIND_WORKSHEET = 'worksheet';

    public const KIND_EXAM_BOOKLET = 'exam_booklet';

    public const KIND_ANSWER_SHEET = 'answer_sheet';

    public const KIND_KEY_SHEET = 'key_sheet';

    /** Kinds a teacher can ask for on POST /exams/{id}/prints (DESIGN §22.15). */
    public const EXAM_KINDS = [self::KIND_EXAM_BOOKLET, self::KIND_ANSWER_SHEET, self::KIND_KEY_SHEET];

    protected $fillable = [
        'assignment_id',
        'layout_version',
        'kind',
        'version_no',
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
            'version_no' => 'integer',
        ];
    }

    protected $attributes = [
        'kind' => self::KIND_WORKSHEET,
    ];

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

    /** What the teacher calls this file, for Thai messages ("รวมไฟล์{noun}ไม่สำเร็จ"). */
    public function fileNoun(): string
    {
        return match ($this->kind) {
            self::KIND_EXAM_BOOKLET => 'เล่มข้อสอบ',
            self::KIND_ANSWER_SHEET => 'กระดาษคำตอบ',
            self::KIND_KEY_SHEET => 'กระดาษเฉลย',
            default => 'ใบงาน',
        };
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
