<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tester's result for one case of QaCatalog (DESIGN §39): status
 * (manual: pass/fail/blocked/skip; usability: success/assisted/fail), and
 * for usability tasks the time in seconds and the number of mistakes.
 *
 * @property int $qa_tester_id
 * @property string $case_code
 * @property string|null $status
 * @property int|null $time_sec
 * @property int|null $error_count
 * @property string|null $note
 */
class QaResult extends Model
{
    protected $fillable = ['qa_tester_id', 'case_code', 'status', 'time_sec', 'error_count', 'note'];

    protected function casts(): array
    {
        return ['time_sec' => 'integer', 'error_count' => 'integer'];
    }

    /** @return BelongsTo<QaTester, $this> */
    public function tester(): BelongsTo
    {
        return $this->belongsTo(QaTester::class, 'qa_tester_id');
    }
}
