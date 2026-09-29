<?php

namespace App\Models;

use App\Domain\AnswerKeys\KeyCompleteness;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.3 `assignments`.
 *
 * status: `draft` until POST /assignments/{id}/layout builds a layout (which
 * needs every show_work/open rubric approved), then `ready` (printable). A
 * change that alters the printed page sends it back to `draft`; the next
 * layout build then creates a new layout_version (DESIGN §5.1).
 *
 * mode (DESIGN §19.5): `worksheet` (the app's printed worksheet) or
 * `freeform` (no layout, graded from whole pages only). For a freeform
 * assignment `ready` means "answer key approved" (key_approved_at set):
 * POST /answer-key/approve moves it from draft to ready, and it goes back
 * to draft (approval cleared) only when a change leaves the key incomplete.
 * Nothing is graded from whole pages before key_approved_at is set.
 *
 * @property int $id
 * @property int $school_id
 * @property int $classroom_id
 * @property int|null $subject_id null only for a Classroom website mirror until its key is approved (DESIGN §19.3)
 * @property int $created_by
 * @property string $title
 * @property string $strictness lenient|normal|strict
 * @property string $status draft|ready|closed
 * @property int|null $current_layout_version
 * @property Carbon|null $due_at
 * @property string $mode worksheet|freeform
 * @property string $source app|classroom_web
 * @property bool $accept_late
 * @property bool $score_only
 * @property string|null $key_origin teacher|document|ai_draft
 * @property Carbon|null $key_approved_at
 * @property int|null $key_approved_by
 * @property int|null $key_extraction_id
 * @property int|null $course_id required for new assignments (DESIGN §20.1); NULL for older ones and Classroom mirrors until approved
 * @property int|null $lesson_plan_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_CLOSED = 'closed';

    public const STRICTNESS = ['lenient', 'normal', 'strict'];

    public const MODE_WORKSHEET = 'worksheet';

    public const MODE_FREEFORM = 'freeform';

    public const MODES = [self::MODE_WORKSHEET, self::MODE_FREEFORM];

    public const SOURCE_APP = 'app';

    public const SOURCE_CLASSROOM_WEB = 'classroom_web';

    public const KEY_TEACHER = 'teacher';

    public const KEY_DOCUMENT = 'document';

    public const KEY_AI_DRAFT = 'ai_draft';

    protected $fillable = [
        'school_id',
        'classroom_id',
        'subject_id',
        'created_by',
        'title',
        'strictness',
        'status',
        'current_layout_version',
        'due_at',
        'mode',
        'source',
        'accept_late',
        'score_only',
        'key_origin',
        'key_approved_at',
        'key_approved_by',
        'key_extraction_id',
        'course_id',
        'lesson_plan_id',
    ];

    protected $attributes = [
        'strictness' => 'normal',
        'status' => self::STATUS_DRAFT,
        'mode' => self::MODE_WORKSHEET,
        'source' => self::SOURCE_APP,
        'accept_late' => true,
        'score_only' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_layout_version' => 'integer',
            'due_at' => 'datetime',
            'accept_late' => 'boolean',
            'score_only' => 'boolean',
            'key_approved_at' => 'datetime',
            'key_approved_by' => 'integer',
            'key_extraction_id' => 'integer',
            'course_id' => 'integer',
            'lesson_plan_id' => 'integer',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<LessonPlan, $this> */
    public function lessonPlan(): BelongsTo
    {
        return $this->belongsTo(LessonPlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Question, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    /** @return HasMany<Layout, $this> */
    public function layouts(): HasMany
    {
        return $this->hasMany(Layout::class);
    }

    /** @return HasMany<WorksheetPrint, $this> */
    public function worksheetPrints(): HasMany
    {
        return $this->hasMany(WorksheetPrint::class);
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /** @return HasManyThrough<Response, Submission, $this> */
    public function responses(): HasManyThrough
    {
        return $this->hasManyThrough(Response::class, Submission::class);
    }

    /** The Google Classroom courseWork it was posted as (DESIGN §18.4). @return HasOne<AssignmentGoogleLink, $this> */
    public function googleLink(): HasOne
    {
        return $this->hasOne(AssignmentGoogleLink::class);
    }

    /** The extraction or AI draft the answer key waits for (DESIGN §19.5). @return BelongsTo<DocumentExtraction, $this> */
    public function keyExtraction(): BelongsTo
    {
        return $this->belongsTo(DocumentExtraction::class, 'key_extraction_id');
    }

    /** @return HasMany<ClassroomSubmissionImport, $this> */
    public function submissionImports(): HasMany
    {
        return $this->hasMany(ClassroomSubmissionImport::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isFreeform(): bool
    {
        return $this->mode === self::MODE_FREEFORM;
    }

    /** Whole pages are graded only once the teacher approved the key (DESIGN §19.5). */
    public function keyApproved(): bool
    {
        return $this->key_approved_at !== null;
    }

    /**
     * A new key (read from a document or drafted by AI) replaced the
     * approved one: a freeform assignment goes back to `draft` until the
     * teacher approves again (ready ⇔ approved, §19.5). A worksheet keeps
     * its status; its layout rules decide.
     */
    public function revokeKeyApproval(): void
    {
        if (! $this->isFreeform()) {
            return;
        }
        $this->key_approved_at = null;
        $this->key_approved_by = null;
        if ($this->isReady()) {
            $this->status = self::STATUS_DRAFT;
        }
        $this->save();
    }

    /**
     * `ready` means "printable from the current layout with every rubric
     * approved" (DESIGN §2.2). When either stops being true (a question that
     * changes the printed page was added, edited or removed, or an approved
     * rubric was re-drafted) the assignment goes back to `draft` until the
     * teacher builds the layout again. Sheets printed earlier keep working
     * because their QR names the layout version they were printed with.
     *
     * A freeform assignment has no printed page: it stays `ready` while its
     * key is still complete (KeyCompleteness), and otherwise loses its
     * approval with the status (ready ⇔ approved, DESIGN §19.5).
     */
    public function backToDraft(): void
    {
        if (! $this->isReady()) {
            return;
        }
        if ($this->isFreeform()) {
            if (KeyCompleteness::missing($this) === []) {
                return;
            }
            $this->key_approved_at = null;
            $this->key_approved_by = null;
        }
        $this->status = self::STATUS_DRAFT;
        $this->save();
    }

    public function currentLayout(): ?Layout
    {
        if ($this->current_layout_version === null) {
            return null;
        }

        return $this->layouts()->where('version', $this->current_layout_version)->first();
    }
}
