<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §22.14 `exam_sections`: one section of an exam. Every question in
 * a section has the section's type; question numbers run on across the
 * whole exam in section order (questions.position).
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $position
 * @property string|null $title
 * @property string|null $instructions
 * @property string $type mcq|true_false|numeric|open|show_work
 * @property int|null $option_count mcq: 2–6
 * @property int|null $numeric_digits numeric: 1–5
 * @property bool $numeric_allow_negative
 * @property bool $numeric_allow_decimal
 * @property float $default_points
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExamSection extends Model
{
    public const TYPE_MCQ = 'mcq';

    public const TYPE_TRUE_FALSE = 'true_false';

    public const TYPE_NUMERIC = 'numeric';

    /** The sheet types: bubbles on the answer sheet. The file import validates against these only. */
    public const TYPES = [self::TYPE_MCQ, self::TYPE_TRUE_FALSE, self::TYPE_NUMERIC];

    /** DESIGN §22.20: open (อัตนัย) and show_work (แสดงวิธีทำ) are written by hand and graded against a rubric. */
    public const TYPE_OPEN = 'open';

    public const TYPE_SHOW_WORK = 'show_work';

    public const WRITTEN_TYPES = [self::TYPE_OPEN, self::TYPE_SHOW_WORK];

    /** Lines of the writing box of a new written question. */
    public const DEFAULT_ANSWER_LINES = 8;

    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 6;

    public const MAX_DIGITS = 5;

    protected $fillable = [
        'assignment_id',
        'position',
        'title',
        'instructions',
        'type',
        'option_count',
        'numeric_digits',
        'numeric_allow_negative',
        'numeric_allow_decimal',
        'default_points',
    ];

    protected $attributes = [
        'numeric_allow_negative' => false,
        'numeric_allow_decimal' => false,
        'default_points' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignment_id' => 'integer',
            'position' => 'integer',
            'option_count' => 'integer',
            'numeric_digits' => 'integer',
            'numeric_allow_negative' => 'boolean',
            'numeric_allow_decimal' => 'boolean',
            'default_points' => 'float',
        ];
    }

    public static function isWrittenType(string $type): bool
    {
        return in_array($type, self::WRITTEN_TYPES, true);
    }

    public function isWritten(): bool
    {
        return self::isWrittenType((string) $this->type);
    }

    /** Options a question of this section has: option_count (mcq), 2 (ถูก/ผิด) or none (numeric, written). */
    public function choiceCount(): int
    {
        return match ($this->type) {
            self::TYPE_MCQ => (int) $this->option_count,
            self::TYPE_TRUE_FALSE => 2,
            default => 0,
        };
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return HasMany<Question, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'section_id')->orderBy('position');
    }
}
