<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §8.6 `training_samples`: a labelled crop for training the digit
 * model (§12.3). writer_key groups samples by writer for the train/test
 * split: a hash of the student id, never the id itself.
 *
 * @property int $id
 * @property int|null $school_id
 * @property string $source collection_sheet|teacher_correction
 * @property string $crop_path
 * @property string $label
 * @property string|null $writer_key
 * @property int|null $response_id
 */
class TrainingSample extends Model
{
    public const SOURCE_COLLECTION_SHEET = 'collection_sheet';

    public const SOURCE_TEACHER_CORRECTION = 'teacher_correction';

    protected $fillable = [
        'school_id',
        'source',
        'crop_path',
        'label',
        'writer_key',
        'response_id',
    ];

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<Response, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }
}
