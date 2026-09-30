<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §22.14 `exam_sheet_reads`: the bubble fill measured on one scanned
 * answer-sheet page (one row per exam `scans` row), as the phone sent it
 * after subtracting the page baseline (§22.9). Fill keys are the displayed
 * positions of the page ("1".."6") and digit values ("-", ".", "0".."9").
 *
 * version_no is NULL while the version is unknown (no or several version
 * bubbles on page 1, or page 2 before page 1): the page then has no
 * responses until the teacher picks the version. version_source says how it
 * was decided: single (one-version exam), bubble (page 1), page_one (a later
 * page takes page 1's), teacher.
 *
 * @property int $scan_id
 * @property int $assignment_id
 * @property array<string, float>|null $version_fill
 * @property int|null $version_no
 * @property string|null $version_source
 * @property array<string, array<string, float>> $rows_fill
 * @property array<string, array{sign: float|null, columns: list<array<string, float>>}>|null $digits_fill
 * @property float|null $device_score
 * @property list<array{sheet_no: int|null, reason: string}>|null $doubts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExamSheetRead extends Model
{
    public const SOURCE_SINGLE = 'single';

    public const SOURCE_BUBBLE = 'bubble';

    public const SOURCE_TEACHER = 'teacher';

    public const SOURCE_PAGE_ONE = 'page_one';

    protected $primaryKey = 'scan_id';

    public $incrementing = false;

    protected $fillable = [
        'scan_id',
        'assignment_id',
        'version_fill',
        'version_no',
        'version_source',
        'rows_fill',
        'digits_fill',
        'device_score',
        'doubts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scan_id' => 'integer',
            'assignment_id' => 'integer',
            'version_fill' => 'array',
            'version_no' => 'integer',
            'rows_fill' => 'array',
            'digits_fill' => 'array',
            'device_score' => 'float',
            'doubts' => 'array',
        ];
    }

    /** @return BelongsTo<Scan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
