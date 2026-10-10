<?php

namespace App\Models;

use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DESIGN §8.2 `subjects`. owner_user_id NULL = a subject group everyone
 * sees (the eight learning areas, and any the curriculum import adds); a
 * user id = that teacher's own group (DESIGN §29.4).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $owner_user_id
 */
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'owner_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['owner_user_id' => 'integer'];
    }

    /**
     * The shared subject groups and the user's own.
     *
     * @param  Builder<Subject>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $b) => $b->whereNull('owner_user_id')->orWhere('owner_user_id', $user->id));
    }

    /** @return HasMany<Skill, $this> */
    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class);
    }
}
