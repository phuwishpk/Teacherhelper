<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tester's System Usability Scale answers (DESIGN §39): ten answers 1–5,
 * the role they tested (teacher/student) and the 0–100 score.
 *
 * @property list<int> $answers
 * @property float $score
 * @property string $role
 */
class QaSusResponse extends Model
{
    protected $fillable = ['qa_tester_id', 'role', 'answers', 'score', 'comment'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'score' => 'float'];
    }

    /** @return BelongsTo<QaTester, $this> */
    public function tester(): BelongsTo
    {
        return $this->belongsTo(QaTester::class, 'qa_tester_id');
    }
}
