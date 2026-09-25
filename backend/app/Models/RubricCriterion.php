<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §8.3 `rubric_criteria`.
 *
 * @property int $id
 * @property int $question_id
 * @property int $position
 * @property string $description
 * @property float $points
 * @property bool $is_core
 * @property string $source ai|teacher
 */
class RubricCriterion extends Model
{
    public const SOURCE_AI = 'ai';

    public const SOURCE_TEACHER = 'teacher';

    protected $table = 'rubric_criteria';

    protected $fillable = [
        'question_id',
        'position',
        'description',
        'points',
        'is_core',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'points' => 'float',
            'is_core' => 'boolean',
        ];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
