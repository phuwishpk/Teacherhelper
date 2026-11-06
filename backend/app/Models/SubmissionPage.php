<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §19.8 `submission_pages`: one file of a whole-page submission
 * (§19.4): a photo (JPEG, PNG, WebP, HEIC/HEIF) or a PDF, stored as it came
 * (the server never converts it) at pages/{school}/{assignment}/{id}.{ext}.
 *
 * state:
 *   stored      kept, not being graded (waits for a regrade the teacher
 *               starts, or for the answer key, §19.5)
 *   grading     GradeSubmissionPageJob is reading it
 *   graded      read; `result` holds its per-question extraction
 *   failed      could not be read (Gemini error after 3 tries, no key)
 *   superseded  replaced by a newer hand-in; its file goes in the next
 *               eduvision:purge-images run
 *
 * result (DESIGN §19.8, added in build step 2): {status, reason?, questions:
 * {question_id: {found, data?, answer_box?, invalid?}}}, what the merge of
 * all pages of the submission reads (WholePageGrader).
 *
 * @property int $id
 * @property int $submission_id
 * @property string $source classroom|student_app|teacher_upload
 * @property string|null $google_submission_id
 * @property string|null $drive_file_id
 * @property int|null $uploaded_by
 * @property int $position
 * @property string $mime_type
 * @property int $page_count
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $file_path
 * @property string $state
 * @property array<string, mixed>|null $result
 * @property Carbon $received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SubmissionPage extends Model
{
    public const SOURCE_CLASSROOM = 'classroom';

    public const SOURCE_STUDENT_APP = 'student_app';

    public const SOURCE_TEACHER_UPLOAD = 'teacher_upload';

    public const STATE_STORED = 'stored';

    public const STATE_GRADING = 'grading';

    public const STATE_GRADED = 'graded';

    public const STATE_FAILED = 'failed';

    public const STATE_SUPERSEDED = 'superseded';

    /** The pages of the submission's current grading round. */
    public const CURRENT_STATES = [self::STATE_GRADING, self::STATE_GRADED, self::STATE_FAILED];

    protected $fillable = [
        'submission_id',
        'source',
        'google_submission_id',
        'drive_file_id',
        'uploaded_by',
        'position',
        'mime_type',
        'page_count',
        'size_bytes',
        'sha256',
        'file_path',
        'state',
        'result',
        'received_at',
    ];

    protected $attributes = [
        'state' => self::STATE_STORED,
        'page_count' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'page_count' => 'integer',
            'size_bytes' => 'integer',
            'result' => 'array',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
