<?php

namespace App\Models;

use App\Domain\AnswerKeys\KeyCompleteness;
use App\Domain\Exams\ExamKeyCheck;
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
 * @property string $kind homework|exam (DESIGN §22.1)
 * @property string|null $grading_method app|manual, exams only
 * @property int $version_count 1..config('eduvision.exams.max_versions') shuffled versions (§22.5)
 * @property int|null $duration_minutes
 * @property bool $show_key_to_students
 * @property float|null $manual_full_marks full marks of a grading_method = manual exam
 * @property int $shuffle_nonce
 * @property Carbon|null $structure_locked_at set by the first print of an exam (§22.2)
 * @property int|null $gradebook_category_id the gradebook category of the course (DESIGN §23.3); NULL = not counted
 * @property bool $excluded_from_grade "ไม่นับเกรด": shown in the gradebook, never in the formula
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

    public const KIND_HOMEWORK = 'homework';

    public const KIND_EXAM = 'exam';

    public const KINDS = [self::KIND_HOMEWORK, self::KIND_EXAM];

    /** "ตรวจด้วยแอป": printed answer sheets scanned and scored by code (DESIGN §22.1). */
    public const GRADING_APP = 'app';

    /** "ครูตรวจเอง": the teacher grades outside the app and types the totals. */
    public const GRADING_MANUAL = 'manual';

    public const GRADING_METHODS = [self::GRADING_APP, self::GRADING_MANUAL];

    /** Answer sheets printed per student with the student's QR (DESIGN §22.6). */
    public const IDENTITY_QR = 'qr';

    /** One answer sheet for everyone; the student fills in their student ID (DESIGN §22.19). */
    public const IDENTITY_CODE = 'code';

    public const SHEET_IDENTITIES = [self::IDENTITY_QR, self::IDENTITY_CODE];

    public const MIN_CODE_DIGITS = 4;

    public const MAX_CODE_DIGITS = 13;

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
        'kind',
        'grading_method',
        'version_count',
        'duration_minutes',
        'show_key_to_students',
        'manual_full_marks',
        'shuffle_nonce',
        'structure_locked_at',
        'sheet_identity',
        'student_code_digits',
        'gradebook_category_id',
        'excluded_from_grade',
    ];

    protected $attributes = [
        'strictness' => 'normal',
        'status' => self::STATUS_DRAFT,
        'mode' => self::MODE_WORKSHEET,
        'source' => self::SOURCE_APP,
        'accept_late' => true,
        'score_only' => false,
        'kind' => self::KIND_HOMEWORK,
        'version_count' => 1,
        'show_key_to_students' => false,
        'shuffle_nonce' => 0,
        'sheet_identity' => self::IDENTITY_QR,
        'excluded_from_grade' => false,
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
            // Compared strictly with courses.subject_id (AssignmentCourses): PDO may return strings.
            'subject_id' => 'integer',
            'version_count' => 'integer',
            'duration_minutes' => 'integer',
            'show_key_to_students' => 'boolean',
            'manual_full_marks' => 'float',
            'shuffle_nonce' => 'integer',
            'structure_locked_at' => 'datetime',
            'student_code_digits' => 'integer',
            'gradebook_category_id' => 'integer',
            'excluded_from_grade' => 'boolean',
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

    /** @return BelongsTo<GradebookCategory, $this> */
    public function gradebookCategory(): BelongsTo
    {
        return $this->belongsTo(GradebookCategory::class);
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

    /** Sections of an exam in order (DESIGN §22.2). @return HasMany<ExamSection, $this> */
    public function examSections(): HasMany
    {
        return $this->hasMany(ExamSection::class)->orderBy('position');
    }

    /** Stored permutations of the shuffled versions (DESIGN §22.5). @return HasMany<ExamVersion, $this> */
    public function examVersions(): HasMany
    {
        return $this->hasMany(ExamVersion::class)->orderBy('version_no');
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

    public function isExam(): bool
    {
        return $this->kind === self::KIND_EXAM;
    }

    /** An exam the teacher grades outside the app: no key gate, `ready` from the start (DESIGN §22.1). */
    public function isManualExam(): bool
    {
        return $this->isExam() && $this->grading_method === self::GRADING_MANUAL;
    }

    /** The exam's answer sheet is the shared one with the student-ID grid (DESIGN §22.19). */
    public function usesCodeSheets(): bool
    {
        return $this->isExam() && $this->sheet_identity === self::IDENTITY_CODE;
    }

    /** Structural changes of an exam are locked once anything was printed (DESIGN §22.2). */
    public function structureLocked(): bool
    {
        return $this->structure_locked_at !== null;
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
     * approval with the status (ready ⇔ approved, DESIGN §19.5). An exam
     * follows ExamKeyCheck::keepApprovalValid (an app exam is ready while its
     * approved key stays complete; a manual exam stays ready, §22.1).
     */
    public function backToDraft(): void
    {
        if (! $this->isReady()) {
            return;
        }
        if ($this->isExam()) {
            ExamKeyCheck::keepApprovalValid($this);

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
