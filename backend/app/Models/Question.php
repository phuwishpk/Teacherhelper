<?php

namespace App\Models;

use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DESIGN §8.3 `questions`. answer_key shapes:
 *   mcq       {"correct": "B"}
 *   short     {"accepted": [...], "numeric"?: {"value": 12.5, "abs_tol": 0.01}}
 *   show_work {"final": {"accepted": [...], "numeric"?: {...}}, "reference_steps": [...]}
 *   open      null (rubric_criteria)
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $position
 * @property string $type mcq|short|show_work|open
 * @property string $prompt_text
 * @property string|null $prompt_image_path
 * @property float $max_points
 * @property int|null $answer_lines
 * @property bool $is_numeric
 * @property string $match_mode flexible|exact
 * @property array<string, mixed>|null $answer_key
 * @property string $rubric_status not_needed|draft|approved
 * @property string|null $model_answer the teacher's model answer of an open question (DESIGN §19.5)
 *
 * A freeform assignment (DESIGN §19.5) may hold questions whose answer_key is
 * still null until the key is typed, read from a document or drafted by AI;
 * approval (KeyCompleteness) requires it.
 */
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    public const TYPE_MCQ = 'mcq';

    public const TYPE_SHORT = 'short';

    public const TYPE_SHOW_WORK = 'show_work';

    public const TYPE_OPEN = 'open';

    public const TYPES = [self::TYPE_MCQ, self::TYPE_SHORT, self::TYPE_SHOW_WORK, self::TYPE_OPEN];

    public const RUBRIC_NOT_NEEDED = 'not_needed';

    public const RUBRIC_DRAFT = 'draft';

    public const RUBRIC_APPROVED = 'approved';

    public const MATCH_MODES = ['flexible', 'exact'];

    /** Options printed for every mcq question (bubbles A–D). */
    public const MCQ_OPTIONS = ['A', 'B', 'C', 'D'];

    protected $fillable = [
        'assignment_id',
        'position',
        'type',
        'prompt_text',
        'prompt_image_path',
        'max_points',
        'answer_lines',
        'is_numeric',
        'match_mode',
        'answer_key',
        'rubric_status',
        'model_answer',
    ];

    protected $attributes = [
        'is_numeric' => false,
        'match_mode' => 'flexible',
        'rubric_status' => self::RUBRIC_NOT_NEEDED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'max_points' => 'float',
            'answer_lines' => 'integer',
            'is_numeric' => 'boolean',
            'answer_key' => 'array',
        ];
    }

    /** show_work and open need a teacher-approved rubric before printing (DESIGN §2.2). */
    public static function typeNeedsRubric(string $type): bool
    {
        return $type === self::TYPE_SHOW_WORK || $type === self::TYPE_OPEN;
    }

    public function needsRubric(): bool
    {
        return self::typeNeedsRubric($this->type);
    }

    public function rubricApproved(): bool
    {
        return ! $this->needsRubric() || $this->rubric_status === self::RUBRIC_APPROVED;
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return HasMany<RubricCriterion, $this> */
    public function rubricCriteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class)->orderBy('position');
    }

    /** @return HasMany<Response, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(Response::class);
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'question_skill')->withTimestamps()->orderBy('skills.code');
    }

    /**
     * Gemini's proposals from the linked lesson plan (DESIGN §20.3); question_skill holds the confirmed ones.
     *
     * @return HasMany<IndicatorSuggestion, $this>
     */
    public function indicatorSuggestions(): HasMany
    {
        return $this->hasMany(IndicatorSuggestion::class);
    }
}
