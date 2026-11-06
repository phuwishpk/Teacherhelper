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
 * questionnaire (§39.9). A `participant` signed themselves up at
 * /usability with a name, an occupation and the group's code, rates the
 * four tasks of the short set there and answers SUS; the account has no
 * usable email or password, so it opens nothing else (§39.10).
 *
 * @property int $id
 * @property string $name
 * @property string|null $occupation
 * @property string $email
 * @property bool $is_lead
 * @property string $role tester|evaluator|participant
 */
class QaTester extends Authenticatable implements FilamentUser, HasName
{
    /** Runs the integration and usability tests in /qa. */
    public const ROLE_TESTER = 'tester';

    /** Answers the SUS questionnaire in /sus only (DESIGN §39.9). */
    public const ROLE_EVALUATOR = 'evaluator';

    /** Signed up at /usability: the short set and SUS, nothing else (DESIGN §39.10). */
    public const ROLE_PARTICIPANT = 'participant';

    /** The roles a tester or an admin gives an account; participants sign themselves up. */
    public const ROLES = [self::ROLE_TESTER => 'ผู้ทดสอบ', self::ROLE_EVALUATOR => 'ผู้ประเมิน SUS'];

    /** @var array<string, string> every role, for lists */
    public const ROLE_LABELS = [...self::ROLES, self::ROLE_PARTICIPANT => 'ผู้เข้าร่วมทดสอบ'];

    protected $fillable = ['name', 'occupation', 'email', 'password', 'is_lead', 'role'];

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

    public function isParticipant(): bool
    {
        return $this->role === self::ROLE_PARTICIPANT;
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
