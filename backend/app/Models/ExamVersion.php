<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §22.14 `exam_versions`: the stored permutation of one version of an
 * exam (version 1 = ก = the original order). Stored rather than recomputed,
 * so a printed version reads back the same even if the shuffle changes.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $version_no
 * @property string $seed hex of the first 8 bytes of the SHA-256 seed input
 * @property string $structure_hash
 * @property list<int> $question_order question ids by the number on the sheet (1..n)
 * @property array<int|string, list<int>> $option_orders {question_id: [original position by displayed position]}, shuffled mcq only
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExamVersion extends Model
{
    protected $fillable = ['assignment_id', 'version_no', 'seed', 'structure_hash', 'question_order', 'option_orders'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignment_id' => 'integer',
            'version_no' => 'integer',
            'question_order' => 'array',
            'option_orders' => 'array',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }
}
