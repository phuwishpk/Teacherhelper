<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §29.12 `timetable_cancellations`: one date a slot does not happen.
 *
 * @property int $id
 * @property int $timetable_slot_id
 * @property Carbon $held_on
 * @property string|null $note
 * @property int $created_by
 */
class TimetableCancellation extends Model
{
    protected $fillable = ['timetable_slot_id', 'held_on', 'note', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['timetable_slot_id' => 'integer', 'held_on' => 'date:Y-m-d', 'created_by' => 'integer'];
    }

    /** @return BelongsTo<TimetableSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(TimetableSlot::class, 'timetable_slot_id');
    }
}
