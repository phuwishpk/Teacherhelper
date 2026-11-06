<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §20.3 / §20.6 `indicator_suggestions`: an indicator of the linked
 * lesson plan that Gemini proposed for a question, with a short reason.
 * Keyed by (question_id, skill_id), so there is no single primary key:
 * IndicatorSuggestions writes the rows with insert() and save() must not
 * be used. The teacher's confirmed choice lives in question_skill.
 *
 * @property int $question_id
 * @property int $skill_id
 * @property string|null $reason_th
 * @property Carbon $created_at
 */
class IndicatorSuggestion extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = null;

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'question_id' => 'integer',
            'skill_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
