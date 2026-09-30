<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §20.6 / §20.8 `analysis_batches`: one Gemini Batch API job of the
 * nightly analysis, for one key (the classroom teacher's, or the server
 * key when key_owner_id is NULL).
 *
 * building -> submitted -> running -> collected (results written), or
 * failed / expired / cancelled. succeeded is Gemini's state between the
 * poll that saw it and the collection (kept for the enum of §20.6).
 *
 * @property int $id
 * @property int|null $key_owner_id
 * @property string|null $batch_name
 * @property string $state
 * @property int $request_count
 * @property Carbon|null $submitted_at
 * @property Carbon|null $last_polled_at
 * @property Carbon|null $completed_at
 * @property string|null $error
 */
class AnalysisBatch extends Model
{
    public const STATE_BUILDING = 'building';

    public const STATE_SUBMITTED = 'submitted';

    public const STATE_RUNNING = 'running';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED = 'failed';

    public const STATE_EXPIRED = 'expired';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_COLLECTED = 'collected';

    /** States of a batch whose rows are still waiting for their texts. */
    public const PENDING_STATES = [self::STATE_BUILDING, self::STATE_SUBMITTED, self::STATE_RUNNING, self::STATE_SUCCEEDED];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_owner_id' => 'integer',
            'request_count' => 'integer',
            'submitted_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<StudentAnalysis, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(StudentAnalysis::class, 'batch_id');
    }
}
