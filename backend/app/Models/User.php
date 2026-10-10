<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * DESIGN §8.1 `users`: admins (school_id NULL), teachers and students share one table.
 *
 * @property int $id
 * @property int|null $school_id
 * @property string $role admin|teacher|student
 * @property string $name
 * @property string|null $email
 * @property string|null $password
 * @property string $status pending|active|disabled
 * @property int|null $approved_by
 * @property string|null $student_code the school student ID, normalised (DESIGN §24.4)
 * @property int|null $merged_into_id this account was merged into that one (DESIGN §24.5)
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TEACHER = 'teacher';

    public const ROLE_STUDENT = 'student';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'school_id',
        'role',
        'name',
        'email',
        'password',
        'status',
        'approved_by',
        'student_code',
        'username',
        'school_name',
        'merged_into_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Classrooms this user teaches (role teacher). @return HasMany<Classroom, $this> */
    public function taughtClassrooms(): HasMany
    {
        return $this->hasMany(Classroom::class, 'teacher_id');
    }

    /**
     * Classrooms this user is enrolled in (role student), student_number on the pivot.
     *
     * @return BelongsToMany<Classroom, $this>
     */
    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'classroom_students', 'student_id', 'classroom_id')
            ->using(ClassroomStudent::class)
            ->withPivot('student_number')
            ->withTimestamps();
    }

    /** @return HasOne<StudentCredential, $this> */
    public function credential(): HasOne
    {
        return $this->hasOne(StudentCredential::class, 'student_id');
    }

    /** @return HasMany<DeviceToken, $this> */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** @return HasOne<TeacherApiKey, $this> */
    public function apiKey(): HasOne
    {
        return $this->hasOne(TeacherApiKey::class);
    }

    /** The teacher's connected Google account (DESIGN §18.4). @return HasOne<GoogleAccount, $this> */
    public function googleAccount(): HasOne
    {
        return $this->hasOne(GoogleAccount::class);
    }

    /** The Google account this user signs in with (DESIGN §24.9). @return HasOne<UserGoogleIdentity, $this> */
    public function googleIdentity(): HasOne
    {
        return $this->hasOne(UserGoogleIdentity::class);
    }

    /** The account this one was merged into (DESIGN §24.5). @return BelongsTo<User, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_into_id');
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->role === self::ROLE_TEACHER;
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    /**
     * Filament web admin (DESIGN §7.5): only active system admins may sign in.
     * Filament enforces this whenever APP_ENV !== local.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === self::ROLE_ADMIN && $this->isActive();
    }
}
