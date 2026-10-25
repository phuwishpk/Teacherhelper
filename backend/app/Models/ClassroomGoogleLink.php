<?php

namespace App\Models;

use App\Domain\Classrooms\ClassroomAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §18.4, §24.3 D `classroom_google_links`: a Google Classroom course
 * linked to a classroom. Since build 4 a classroom has one link per teacher
 * (unique classroom_id + owner_user_id): the homeroom teacher's course and
 * each subject teacher's own. app_course_id is the app course the Google
 * course stands for; work of a course goes through the link of the course's
 * creator (forAssignment).
 *
 * @property int $id
 * @property int $classroom_id
 * @property string $course_id
 * @property string $course_name
 * @property int $owner_user_id
 * @property int|null $app_course_id
 * @property Carbon $linked_at
 * @property Carbon|null $roster_synced_at the last roster sync (DESIGN §19.2)
 * @property Carbon|null $work_synced_at the last work sync (DESIGN §19.3)
 */
class ClassroomGoogleLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'classroom_id',
        'course_id',
        'course_name',
        'owner_user_id',
        'app_course_id',
        'linked_at',
        'roster_synced_at',
        'work_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classroom_id' => 'integer',
            'owner_user_id' => 'integer',
            'app_course_id' => 'integer',
            'linked_at' => 'datetime',
            'roster_synced_at' => 'datetime',
            'work_synced_at' => 'datetime',
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

    /** @return BelongsTo<Course, $this> */
    public function appCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'app_course_id');
    }

    /** The link $ownerId made in the classroom, if any. */
    public static function of(int $classroomId, int $ownerId): ?self
    {
        return self::query()->where('classroom_id', $classroomId)->where('owner_user_id', $ownerId)->first();
    }

    /**
     * The link an assignment is posted and synced through (DESIGN §24.10):
     * the one owned by the teacher who manages its work (the creator of its
     * course, or the homeroom teacher for work without a course).
     */
    public static function forAssignment(Assignment $assignment): ?self
    {
        $ownerId = ClassroomAccess::managerId($assignment);

        return $ownerId === null ? null : self::of((int) $assignment->classroom_id, $ownerId);
    }

    /** The owner is the classroom's homeroom teacher (their course may add students, §24.10). */
    public function isHomeroomLink(?Classroom $classroom = null): bool
    {
        $classroom ??= $this->classroom;

        return $classroom !== null && (int) $classroom->teacher_id === (int) $this->owner_user_id;
    }

    /**
     * `google_link` of a classroom (GET /classrooms, POST .../google-link).
     * roster_synced_at / work_synced_at: the last roster sync and the last
     * work sync round (DESIGN §19.2, §19.3), shown next to "ซิงก์ตอนนี้".
     *
     * id, owner_user_id and app_course_id were added in build 4 (§24.10).
     *
     * @return array{id: int, course_id: string, course_name: string, owner_user_id: int, app_course_id: int|null, linked_at: string|null, roster_synced_at: string|null, work_synced_at: string|null}
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'course_name' => $this->course_name,
            'owner_user_id' => $this->owner_user_id,
            'app_course_id' => $this->app_course_id,
            'linked_at' => $this->linked_at?->toIso8601String(),
            'roster_synced_at' => $this->roster_synced_at?->toIso8601String(),
            'work_synced_at' => $this->work_synced_at?->toIso8601String(),
        ];
    }
}
