<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §8.5 `learning_resources`: a review link of a skill, added by a
 * teacher of the school, shown to students with their practice (§14.1).
 *
 * @property int $id
 * @property int $school_id
 * @property int $skill_id
 * @property string $title
 * @property string $url
 * @property int $added_by
 */
class LearningResource extends Model
{
    protected $fillable = [
        'school_id',
        'skill_id',
        'title',
        'url',
        'added_by',
    ];

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /** @return BelongsTo<User, $this> */
    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
