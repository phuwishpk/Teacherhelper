<?php

namespace App\Models;

use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DESIGN §8.1 `schools`.
 *
 * @property int $id
 * @property string $name
 * @property string $teacher_join_code
 * @property bool $allow_training_data
 * @property \Illuminate\Support\Carbon|null $crop_retention_until
 */
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'teacher_join_code',
        'allow_training_data',
        'crop_retention_until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_training_data' => 'boolean',
            'crop_retention_until' => 'date',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
