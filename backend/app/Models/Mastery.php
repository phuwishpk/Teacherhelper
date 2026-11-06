<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.5 `mastery`: the EWMA of a student's observations on a skill
 * (§14.2), keyed by (student_id, skill_id). Read-only through Eloquent:
 * MasteryCalculator writes it with an upsert, so there is no single
 * primary key and save() must not be used.
 *
 * @property int $student_id
 * @property int $skill_id
 * @property float $value
 * @property int $n_obs
 * @property Carbon|null $created_at
 * @property Carbon $updated_at
 */
class Mastery extends Model
{
    protected $table = 'mastery';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'skill_id' => 'integer',
            'value' => 'float',
            'n_obs' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
