<?php

namespace App\Models;

use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.3 `assignments`.
 *
 * status: `draft` until POST /assignments/{id}/layout builds a layout (which
 * needs every show_work/open rubric approved), then `ready` (printable). A
 * change that alters the printed page sends it back to `draft`; the next
 * layout build then creates a new layout_version (DESIGN §5.1).
 *
 * @property int $id
 * @property int $school_id
 * @property int $classroom_id
 * @property int $subject_id
 * @property int $created_by
 * @property string $title
 * @property string $strictness lenient|normal|strict
 * @property string $status draft|ready|closed
 * @property int|null $current_layout_version
 * @property Carbon|null $due_at
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
    ];

    protected $attributes = [
        'strictness' => 'normal',
        'status' => self::STATUS_DRAFT,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_layout_version' => 'integer',
            'due_at' => 'datetime',
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

    /**
     * `ready` means "printable from the current layout with every rubric
     * approved" (DESIGN §2.2). When either stops being true (a question that
     * changes the printed page was added, edited or removed, or an approved
     * rubric was re-drafted) the assignment goes back to `draft` until the
     * teacher builds the layout again. Sheets printed earlier keep working
     * because their QR names the layout version they were printed with.
     */
    public function backToDraft(): void
    {
        if ($this->isReady()) {
            $this->status = self::STATUS_DRAFT;
            $this->save();
        }
    }

    public function currentLayout(): ?Layout
    {
        if ($this->current_layout_version === null) {
            return null;
        }

        return $this->layouts()->where('version', $this->current_layout_version)->first();
    }
}
