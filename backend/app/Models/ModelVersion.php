<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DESIGN §8.6 `model_versions`: an exported on-device model (§12), unique
 * per (name, version). At most one version of a name is active: the one
 * GET /ml/models/active?name= hands to the app (§9.8). metrics is the
 * exported metrics.json (ml/models/<name>/<version>/metrics.json) and
 * carries the decode contract the app reads.
 *
 * @property int $id
 * @property string $name
 * @property string $version
 * @property string $file_path
 * @property string $sha256
 * @property array<string, mixed> $metrics
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ModelVersion extends Model
{
    public const DIGIT_CRNN = 'digit_crnn';

    protected $fillable = [
        'name',
        'version',
        'file_path',
        'sha256',
        'metrics',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<ModelVersion>  $query
     */
    public function scopeActiveNamed(Builder $query, string $name): void
    {
        $query->where('name', $name)->where('is_active', true);
    }

    /** Makes this the only active version of its name. */
    public function activate(): void
    {
        DB::transaction(function () {
            static::query()->where('name', $this->name)->whereKeyNot($this->getKey())->update(['is_active' => false]);
            $this->is_active = true;
            $this->save();
        });
    }

    /** Where the file lives on the private disk (DESIGN §7.3). */
    public static function pathFor(string $name, string $version): string
    {
        return "models/{$name}/{$version}.tflite";
    }
}
