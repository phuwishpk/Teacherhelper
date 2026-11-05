<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tester's result for one case of QaCatalog (DESIGN §39): status
 * (manual: pass/fail/blocked/skip; usability: success/assisted/fail), and
 * for usability tasks the time in seconds and the number of mistakes, or,
 * when a participant rated it themselves at /usability, how easy it was
 * from 1 to 5 (DESIGN §39.10).
 *
 * @property int $qa_tester_id
 * @property string $case_code
 * @property string|null $status
 * @property int|null $time_sec
 * @property int|null $error_count
 * @property int|null $ease
 * @property string|null $note
 * @property array<string, string>|null $checks alternative path label => pass|fail
 */
class QaResult extends Model
{
    protected $fillable = ['qa_tester_id', 'case_code', 'status', 'time_sec', 'error_count', 'ease', 'note', 'checks'];

    protected function casts(): array
    {
        return ['time_sec' => 'integer', 'error_count' => 'integer', 'ease' => 'integer', 'checks' => 'array'];
    }

    /** @return BelongsTo<QaTester, $this> */
    public function tester(): BelongsTo
    {
        return $this->belongsTo(QaTester::class, 'qa_tester_id');
    }
}
