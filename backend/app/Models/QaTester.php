<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Someone who tests Krucheck (DESIGN §39). Not a Krucheck user. A `tester`
 * signs in to the Test Lab at /qa (guard `qa`) and records integration and
 * usability results; `is_lead` marks the person running the tests. An
 * `evaluator` signs in to the separate SUS pages at /sus (guard `sus`, plain
 * pages of their own look, not a Filament panel) and only answers the SUS
 * questionnaire (§39.7).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_lead
 * @property string $role tester|evaluator
 */
class QaTester extends Authenticatable implements FilamentUser, HasName
{
    /** Runs the integration and usability tests in /qa. */
    public const ROLE_TESTER = 'tester';

    /** Answers the SUS questionnaire in /sus only (DESIGN §39.7). */
    public const ROLE_EVALUATOR = 'evaluator';

    public const ROLES = [self::ROLE_TESTER => 'ผู้ทดสอบ', self::ROLE_EVALUATOR => 'ผู้ประเมิน SUS'];

    protected $fillable = ['name', 'email', 'password', 'is_lead', 'role'];

    protected $attributes = ['role' => self::ROLE_TESTER];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_lead' => 'boolean',
        ];
    }

    /** Only testers open the Test Lab; evaluators use the SUS pages (/sus, not a panel). */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'qa' && $this->role === self::ROLE_TESTER;
    }

    public function isEvaluator(): bool
    {
        return $this->role === self::ROLE_EVALUATOR;
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

    /** An evaluator's SUS answers, one per role of the app they used. @return HasMany<QaSusResponse, $this> */
    public function susResponses(): HasMany
    {
        return $this->hasMany(QaSusResponse::class);
    }
}
