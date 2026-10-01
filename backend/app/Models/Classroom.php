<?php

namespace App\Models;

use Database\Factories\ClassroomFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.1 `classrooms`.
 *
 * @property int $id
 * @property int $school_id
 * @property int $teacher_id
 * @property string $name
 * @property int $grade_level
 * @property int $academic_year
 * @property string $class_code
 * @property bool $auto_share_analysis new analysis texts go to students without approval (§20.5)
 * @property Carbon|null $closed_at closed = "ห้องเก่า", read-only (DESIGN §24.6)
 * @property int|null $closed_by
 */
class Classroom extends Model
{
    /** @use HasFactory<ClassroomFactory> */
    use HasFactory;

    protected $fillable = [
        'school_id',
        'teacher_id',
        'name',
        'grade_level',
        'academic_year',
        'class_code',
        'auto_share_analysis',
        'closed_at',
        'closed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade_level' => 'integer',
            'academic_year' => 'integer',
            'auto_share_analysis' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Open classrooms only (DESIGN §24.6: closed ones are hidden from every picker).
     *
     * @param  Builder<Classroom>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('classrooms.closed_at');
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Students of this classroom with their student_number (เลขที่) on the pivot.
     *
     * @return BelongsToMany<User, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classroom_students', 'classroom_id', 'student_id')
            ->using(ClassroomStudent::class)
            ->withPivot('student_number', 'left_course_at', 'pin_pending_at')
            ->withTimestamps()
            ->orderByPivot('student_number');
    }

    /**
     * The students still in the class: those whose Google account left the
     * linked course (left_course_at, DESIGN §19.2) stay in the room with
     * their scores, but class aggregates (means, pass rates, the nightly
     * analysis) leave them out. Every student of a room that is not linked.
     *
     * @return BelongsToMany<User, $this>
     */
    public function currentStudents(): BelongsToMany
    {
        return $this->students()->wherePivotNull('left_course_at');
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /** Courses (รายวิชา) taught in this classroom (DESIGN §20.1). @return BelongsToMany<Course, $this> */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_classroom');
    }

    /** The linked Google Classroom course (DESIGN §18.4). @return HasOne<ClassroomGoogleLink, $this> */
    public function googleLink(): HasOne
    {
        return $this->hasOne(ClassroomGoogleLink::class);
    }
}
