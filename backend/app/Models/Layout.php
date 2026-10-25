<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §8.3 `layouts`: one version of an assignment's worksheet layout.
 * `pages` holds the per-page layout JSON of DESIGN §5.3.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $version
 * @property array<int, array<string, mixed>> $pages
 */
class Layout extends Model
{
    protected $fillable = [
        'assignment_id',
        'version',
        'pages',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'pages' => 'array',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }
}
