<?php

namespace App\Models;

use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.1 `schools`.
 *
 * @property int $id
 * @property string $name
 * @property string $teacher_join_code
 * @property bool $allow_training_data
 * @property Carbon|null $crop_retention_until
 * @property list<string>|null $google_signin_domains e-mail domains allowed to sign in with Google; null or [] = any (DESIGN §24.9.2)
 * @property bool $student_google_signin the PDPA switch: may this school's students use Google sign-in
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
        'google_signin_domains',
        'student_google_signin',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_training_data' => 'boolean',
            'crop_retention_until' => 'date',
            'google_signin_domains' => 'array',
            'student_google_signin' => 'boolean',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<User, $this> */
    public function teachers(): HasMany
    {
        return $this->hasMany(User::class)->where('role', User::ROLE_TEACHER);
    }

    /** @return HasMany<Classroom, $this> */
    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    /**
     * Whether $domain (lower case, the part after the last `@` of a verified
     * e-mail) may sign in with Google for this school (DESIGN §24.9.2): any
     * domain when the list is empty, else an exact match (no subdomains).
     */
    public function allowsGoogleDomain(string $domain): bool
    {
        $domains = array_values(array_filter(
            array_map(fn ($d) => mb_strtolower(trim((string) $d)), (array) ($this->google_signin_domains ?? [])),
            fn (string $d) => $d !== '',
        ));

        return $domains === [] || in_array(mb_strtolower($domain), $domains, true);
    }

    /** 8 uppercase letters/digits without 0/O/1/I, the code teachers type at sign-up. */
    public static function randomJoinCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
