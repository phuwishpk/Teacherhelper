<?php

namespace App\Models;

use App\Domain\Grading\ScanGrader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.4 `responses`: a student's answer to one question, unique per
 * (submission, question). A rescan of the page updates this row: new scan_id,
 * crops and phone readings, grading fields reset (App\Domain\Scans\ResponseWriter).
 *
 * grading_state: `queued` waits for GradeScanJob (Gemini + fuzzy),
 * `extracted` has Gemini output but no score yet, `scored` has an AI score
 * (mcq is scored at upload, §11.6), `failed` will be retried, `manual` must be
 * graded by the teacher (reason in fuzzy_trace.manual_reason, e.g.
 * ai_key_missing, ai_key_invalid, ai_error, invalid_output, fuzzy_degenerate,
 * crop_missing, layout_type_mismatch, answer_key_missing; see ScanGrader).
 *
 * @property int $id
 * @property int $submission_id
 * @property int $question_id
 * @property int $scan_id
 * @property string|null $crop_path
 * @property string|null $final_crop_path
 * @property float|null $ink_ratio
 * @property array<string, float>|null $mcq_fill
 * @property string|null $cnn_text
 * @property float|null $cnn_confidence
 * @property string $grading_state
 * @property int $attempts
 * @property array<string, mixed>|null $extraction
 * @property array<string, mixed>|null $fuzzy_trace
 * @property float|null $ai_score
 * @property string|null $ai_understanding
 * @property list<string>|null $ai_error_types
 * @property float|null $review_priority
 * @property string|null $priority_band
 * @property float|null $final_score
 * @property string|null $final_understanding
 * @property list<string>|null $final_error_types
 * @property string|null $explanation
 * @property bool $explanation_edited
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Response extends Model
{
    public const STATE_QUEUED = 'queued';

    public const STATE_EXTRACTED = 'extracted';

    public const STATE_SCORED = 'scored';

    public const STATE_FAILED = 'failed';

    public const STATE_MANUAL = 'manual';

    /** States GradeScanJob still has to finish. */
    public const IN_PROGRESS_STATES = [self::STATE_QUEUED, self::STATE_EXTRACTED, self::STATE_FAILED];

    public const UNDERSTANDING = ['good', 'partial', 'not_yet'];

    public const BANDS = ['check', 'look', 'confident'];

    protected $fillable = [
        'submission_id',
        'question_id',
        'scan_id',
        'crop_path',
        'final_crop_path',
        'ink_ratio',
        'mcq_fill',
        'cnn_text',
        'cnn_confidence',
        'grading_state',
        'attempts',
        'extraction',
        'fuzzy_trace',
        'ai_score',
        'ai_understanding',
        'ai_error_types',
        'review_priority',
        'priority_band',
        'final_score',
        'final_understanding',
        'final_error_types',
        'explanation',
        'explanation_edited',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $attributes = [
        'grading_state' => self::STATE_QUEUED,
        'attempts' => 0,
        'explanation_edited' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ink_ratio' => 'float',
            'mcq_fill' => 'array',
            'cnn_confidence' => 'float',
            'attempts' => 'integer',
            'extraction' => 'array',
            'fuzzy_trace' => 'array',
            'ai_score' => 'float',
            'ai_error_types' => 'array',
            'review_priority' => 'float',
            'final_score' => 'float',
            'final_error_types' => 'array',
            'explanation_edited' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** @return BelongsTo<Scan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<ScoreEvent, $this> */
    public function scoreEvents(): HasMany
    {
        return $this->hasMany(ScoreEvent::class);
    }

    /** @return HasOne<Appeal, $this> */
    public function appeal(): HasOne
    {
        return $this->hasOne(Appeal::class);
    }

    /** Why the answer is `manual` (fuzzy_trace.manual_reason), null otherwise. */
    public function manualReason(): ?string
    {
        if ($this->grading_state !== self::STATE_MANUAL) {
            return null;
        }
        $reason = $this->fuzzy_trace['manual_reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * `manual` because there was no usable Gemini key, and not yet graded by
     * the teacher: what the missing-key banner counts and requeues (§13).
     *
     * @param  Builder<Response>  $query
     */
    public function scopeAwaitingAiKey(Builder $query): void
    {
        $query->where('grading_state', self::STATE_MANUAL)
            ->whereNull('reviewed_at')
            ->whereIn('fuzzy_trace->manual_reason', ScanGrader::KEY_REASONS);
    }

    /** The score that currently counts: the teacher's if reviewed, else the AI's. */
    public function effectiveScore(): ?float
    {
        return $this->final_score ?? $this->ai_score;
    }

    public function effectiveUnderstanding(): ?string
    {
        return $this->final_understanding ?? $this->ai_understanding;
    }
}
