<?php

namespace App\Models;

use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 *
 * Exam questions (DESIGN §22.2, §22.3) belong to a section (section_id) and
 * use the types mcq, true_false and numeric with their own key shape:
 *   mcq, true_false  {"accepted_options": [3]}      original positions (1 = ก / ถูก)
 *   numeric          {"accepted_values": ["0.5"]}   canonical (NumericAnswer)
 * position is the question number across the whole exam.
 * @property int|null $section_id
 * @property bool $lock_options "ห้ามสลับตัวเลือก"
 * @property Carbon|null $approved_at
 * @property string|null $origin teacher|document|copied
 * @property int|null $copied_from_question_id
 * @property array<string, mixed>|null $figure_source {page_image_id, box_2d} plus, for a figure read
 *                                                    from a file (DESIGN §22.4), source_document_id and page_no;
 *                                                    page_image_id is null while the page image is still missing
 * @property bool $lock_options_suggested Gemini suggested "ห้ามสลับตัวเลือก" when reading the exam file (a suggestion only)
 */
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    public const TYPE_MCQ = 'mcq';

    public const TYPE_SHORT = 'short';

    public const TYPE_SHOW_WORK = 'show_work';

    public const TYPE_OPEN = 'open';

    /** Types of homework questions. */
    public const TYPES = [self::TYPE_MCQ, self::TYPE_SHORT, self::TYPE_SHOW_WORK, self::TYPE_OPEN];

    public const TYPE_TRUE_FALSE = 'true_false';

    public const TYPE_NUMERIC = 'numeric';

    /** Types of exam questions (the type of their section, DESIGN §22.2). */
    public const EXAM_TYPES = [self::TYPE_MCQ, self::TYPE_TRUE_FALSE, self::TYPE_NUMERIC];

    public const ORIGIN_TEACHER = 'teacher';

    public const ORIGIN_DOCUMENT = 'document';

    public const ORIGIN_COPIED = 'copied';

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
        'section_id',
        'lock_options',
        'approved_at',
        'origin',
        'copied_from_question_id',
        'figure_source',
        'lock_options_suggested',
    ];

    protected $attributes = [
        'is_numeric' => false,
        'match_mode' => 'flexible',
        'rubric_status' => self::RUBRIC_NOT_NEEDED,
        'lock_options' => false,
        'lock_options_suggested' => false,
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
            'section_id' => 'integer',
            'lock_options' => 'boolean',
            'approved_at' => 'datetime',
            'copied_from_question_id' => 'integer',
            'figure_source' => 'array',
            'lock_options_suggested' => 'boolean',
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

    /** The exam section of an exam question (DESIGN §22.2). @return BelongsTo<ExamSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'section_id');
    }

    /** Options of an exam mcq question in the original order. @return HasMany<QuestionOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    public function isExamQuestion(): bool
    {
        return $this->section_id !== null;
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
