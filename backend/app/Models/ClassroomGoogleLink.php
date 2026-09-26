<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §18.4 `classroom_google_links`: the Classroom course of a classroom.
 *
 * @property int $classroom_id
 * @property string $course_id
 * @property string $course_name
 * @property int $owner_user_id
 * @property Carbon $linked_at
 */
class ClassroomGoogleLink extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'classroom_id';

    public $incrementing = false;

    protected $fillable = [
        'classroom_id',
        'course_id',
        'course_name',
        'owner_user_id',
        'linked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * `google_link` of a classroom (GET /classrooms, POST .../google-link).
     *
     * @return array{course_id: string, course_name: string, linked_at: string|null}
     */
    public function toApi(): array
    {
        return [
            'course_id' => $this->course_id,
            'course_name' => $this->course_name,
            'linked_at' => $this->linked_at?->toIso8601String(),
        ];
    }
}
