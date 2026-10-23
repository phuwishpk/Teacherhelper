<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An evaluator's System Usability Scale answers (DESIGN §39.7) for one role
 * of the app (teacher/student): ten answers 1–5, the 0–100 score, Bangor's
 * 1–7 adjective rating, the platform and whether it was their first use.
 *
 * @property list<int> $answers
 * @property float $score
 * @property string $role
 */
class QaSusResponse extends Model
{
    protected $fillable = ['qa_tester_id', 'role', 'answers', 'score', 'adjective', 'platform', 'experience', 'comment'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'score' => 'float', 'adjective' => 'integer'];
    }

    /** @return BelongsTo<QaTester, $this> */
    public function tester(): BelongsTo
    {
        return $this->belongsTo(QaTester::class, 'qa_tester_id');
    }
}
