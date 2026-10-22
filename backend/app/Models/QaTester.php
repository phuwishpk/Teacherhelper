<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Someone who tests Krucheck in the Test Lab at /qa (DESIGN §39), signed in
 * with the `qa` guard. Not a Krucheck user: opens /qa only. `is_lead` marks
 * the person running the tests (sees who still has work left, refreshes
 * the CI status); everyone else fills in their own results.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_lead
 */
class QaTester extends Authenticatable implements FilamentUser, HasName
{
    protected $fillable = ['name', 'email', 'password', 'is_lead'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_lead' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'qa';
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /** @return HasMany<QaResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(QaResult::class);
    }

    /** @return HasOne<QaSusResponse, $this> */
    public function sus(): HasOne
    {
        return $this->hasOne(QaSusResponse::class);
    }
}
