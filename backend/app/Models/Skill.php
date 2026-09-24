<?php

namespace App\Models;

use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DESIGN §8.2 `skills`: a curriculum indicator (school_id NULL) or a school's
 * own sub-skill under one (school_id set, parent_id set).
 *
 * @property int $id
 * @property int $subject_id
 * @property int|null $parent_id
 * @property int|null $school_id
 * @property string $code
 * @property string $name
 * @property int|null $grade_level
 */
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'parent_id',
        'school_id',
        'code',
        'name',
        'grade_level',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade_level' => 'integer',
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
