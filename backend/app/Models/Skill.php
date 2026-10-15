<?php

namespace App\Models;

use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DESIGN §8.2 / §20.2 `skills`: the core curriculum as a tree (school_id
 * NULL: strand → standard → indicator) plus what a school adds under it
 * (school_id set): sub-skills from an admin's CSV (source school_admin) and
 * indicators or sub-indicators a teacher added (source teacher, shared with
 * the whole school, labelled "ครูเพิ่มเอง"). Questions, plans and mastery use
 * the indicator and sub_indicator levels only.
 *
 * @property int $id
 * @property int $subject_id
 * @property int|null $parent_id
 * @property int|null $school_id
 * @property string $code
 * @property string $name
 * @property int|null $grade_level
 * @property string $level strand|standard|indicator|sub_indicator
 * @property string $source curriculum|school_admin|teacher
 * @property int|null $created_by
 */
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    public const LEVEL_STRAND = 'strand';

    public const LEVEL_STANDARD = 'standard';

    public const LEVEL_INDICATOR = 'indicator';

    public const LEVEL_SUB_INDICATOR = 'sub_indicator';

    public const LEVELS = [self::LEVEL_STRAND, self::LEVEL_STANDARD, self::LEVEL_INDICATOR, self::LEVEL_SUB_INDICATOR];

    /** The levels a question, a course, a unit or a plan can use (DESIGN §20.2). */
    public const ASSESSABLE_LEVELS = [self::LEVEL_INDICATOR, self::LEVEL_SUB_INDICATOR];

    public const SOURCE_CURRICULUM = 'curriculum';

    public const SOURCE_SCHOOL_ADMIN = 'school_admin';

    public const SOURCE_TEACHER = 'teacher';

    /** The label the app shows next to an indicator a teacher added (DESIGN §20.2). */
    public const TEACHER_LABEL = 'ครูเพิ่มเอง';

    protected $fillable = [
        'subject_id',
        'parent_id',
        'school_id',
        'code',
        'name',
        'grade_level',
        'level',
        'source',
        'created_by',
    ];

    protected $attributes = [
        'level' => self::LEVEL_INDICATOR,
        'source' => self::SOURCE_CURRICULUM,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade_level' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'parent_id');
    }

    /** @return HasMany<Skill, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Skill::class, 'parent_id');
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isAssessable(): bool
    {
        return in_array($this->level, self::ASSESSABLE_LEVELS, true);
    }

    /** "ครูเพิ่มเอง" for an indicator a teacher added, null otherwise. */
    public function sourceLabel(): ?string
    {
        return $this->source === self::SOURCE_TEACHER ? self::TEACHER_LABEL : null;
    }

    /**
     * Curriculum skills plus the sub-skills of one school.
     *
     * @param  Builder<Skill>  $query
     * @return Builder<Skill>
     */
    public function scopeVisibleToSchool(Builder $query, ?int $schoolId): Builder
    {
        return $query->where(function (Builder $q) use ($schoolId) {
            $q->whereNull('school_id');
            if ($schoolId !== null) {
                $q->orWhere('school_id', $schoolId);
            }
        });
    }
}
