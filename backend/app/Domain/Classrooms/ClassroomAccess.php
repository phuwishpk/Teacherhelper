<?php

namespace App\Domain\Classrooms;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Who a teacher is to a classroom (DESIGN §24.2, §24.8), the one helper
 * every policy and teacher query goes through:
 *
 * - homeroom: the classroom's owner (classrooms.teacher_id);
 * - subject: the creator of a course bound to the classroom (course_classroom)
 *   who is not its homeroom teacher;
 * - null: neither (the classroom is invisible: 404).
 *
 * A homeroom teacher who binds their own course has both roles; courseIds
 * always lists the caller's own courses bound to the classroom.
 *
 * An assignment is managed (edited, printed, scanned, reviewed, published)
 * by the creator of its course while that course is bound to the classroom,
 * or by the homeroom teacher when it has no course (older work). Its results
 * are seen by its manager and by the homeroom teacher (read-only).
 */
final class ClassroomAccess
{
    public const HOMEROOM = 'homeroom';

    public const SUBJECT = 'subject';

    public const NOT_HOMEROOM = 'not_homeroom_teacher';

    public const NOT_COURSE_TEACHER = 'not_course_teacher';

    /**
     * @param  list<int>  $courseIds  the user's own courses bound to the classroom
     */
    private function __construct(
        public readonly string $role,
        public readonly array $courseIds,
    ) {}

    public static function for(User $user, Classroom $classroom): ?self
    {
        if (! self::isSchoolTeacher($user) || (int) $classroom->school_id !== (int) $user->school_id) {
            return null;
        }
        $courseIds = DB::table('course_classroom')
            ->join('courses', 'courses.id', '=', 'course_classroom.course_id')
            ->where('course_classroom.classroom_id', $classroom->id)
            ->where('courses.created_by', $user->id)
            ->where('courses.school_id', $user->school_id)
            ->orderBy('courses.id')
            ->pluck('courses.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ((int) $classroom->teacher_id === (int) $user->id) {
            return new self(self::HOMEROOM, $courseIds);
        }

        return $courseIds === [] ? null : new self(self::SUBJECT, $courseIds);
    }

    public function isHomeroom(): bool
    {
        return $this->role === self::HOMEROOM;
    }

    public function teaches(?int $courseId): bool
    {
        return $courseId !== null && in_array($courseId, $this->courseIds, true);
    }

    /** The user is the homeroom teacher of the classroom. */
    public static function homeroomOf(User $user, Classroom $classroom): bool
    {
        return self::isSchoolTeacher($user)
            && (int) $classroom->teacher_id === (int) $user->id
            && (int) $classroom->school_id === (int) $user->school_id;
    }

    /**
     * Classrooms the teacher sees: homeroom or subject.
     *
     * @return Builder<Classroom>
     */
    public static function classrooms(User $teacher): Builder
    {
        return Classroom::query()
            ->where('classrooms.school_id', $teacher->school_id)
            ->where(fn (Builder $q) => $q
                ->where('classrooms.teacher_id', $teacher->id)
                ->orWhereIn('classrooms.id', self::subjectClassroomIds($teacher)));
    }

    /**
     * Classrooms the teacher is the homeroom teacher of.
     *
     * @return Builder<Classroom>
     */
    public static function homeroomClassrooms(User $teacher): Builder
    {
        return Classroom::query()
            ->where('classrooms.school_id', $teacher->school_id)
            ->where('classrooms.teacher_id', $teacher->id);
    }

    /** Ids of the classrooms an own course of the teacher is bound to (a subquery). */
    public static function subjectClassroomIds(User $teacher): QueryBuilder
    {
        return DB::table('course_classroom')
            ->select('course_classroom.classroom_id')
            ->join('courses', 'courses.id', '=', 'course_classroom.course_id')
            ->where('courses.created_by', $teacher->id)
            ->where('courses.school_id', $teacher->school_id);
    }

    /**
     * Assignments whose results the teacher sees: every assignment of their
     * homerooms, and the ones they manage elsewhere.
     *
     * @return Builder<Assignment>
     */
    public static function assignments(User $teacher): Builder
    {
        return Assignment::query()
            ->where('assignments.school_id', $teacher->school_id)
            ->where(fn (Builder $q) => $q
                ->whereIn('assignments.classroom_id', self::homeroomClassrooms($teacher)->select('classrooms.id'))
                ->orWhere(fn (Builder $q) => self::managedBy($q, $teacher)));
    }

    /**
     * Assignments the teacher manages (DESIGN §24.8 "manage").
     *
     * @return Builder<Assignment>
     */
    public static function managedAssignments(User $teacher): Builder
    {
        return Assignment::query()
            ->where('assignments.school_id', $teacher->school_id)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q
                    ->whereNull('assignments.course_id')
                    ->whereIn('assignments.classroom_id', self::homeroomClassrooms($teacher)->select('classrooms.id')))
                ->orWhere(fn (Builder $q) => self::managedBy($q, $teacher)));
    }

    /** The teacher manages the assignment: see the class comment. */
    public static function manages(User $user, Assignment $assignment): bool
    {
        if (! self::isSchoolTeacher($user) || (int) $assignment->school_id !== (int) $user->school_id) {
            return false;
        }
        if ($assignment->course_id === null) {
            $classroom = $assignment->classroom;

            return $classroom !== null && self::homeroomOf($user, $classroom);
        }

        return DB::table('courses')
            ->join('course_classroom', 'course_classroom.course_id', '=', 'courses.id')
            ->where('courses.id', $assignment->course_id)
            ->where('courses.created_by', $user->id)
            ->where('courses.school_id', $user->school_id)
            ->where('course_classroom.classroom_id', $assignment->classroom_id)
            ->exists();
    }

    /** The teacher sees the assignment's results: its manager or the homeroom teacher. */
    public static function seesResults(User $user, Assignment $assignment): bool
    {
        $classroom = $assignment->classroom;
        if ($classroom !== null && (int) $assignment->school_id === (int) $user->school_id && self::homeroomOf($user, $classroom)) {
            return true;
        }

        return self::manages($user, $assignment);
    }

    /**
     * The policy answer of a "manage" ability: allowed, a 403
     * not_course_teacher for a teacher who sees the work but does not manage
     * it (the homeroom teacher on a subject teacher's work), else a plain deny.
     */
    public static function manageResponse(User $user, Assignment $assignment): Response|bool
    {
        if (self::manages($user, $assignment)) {
            return true;
        }

        return self::seesResults($user, $assignment) ? self::denyNotCourseTeacher() : false;
    }

    /**
     * The teacher who owns the work of the assignment: the creator of its
     * course, or the homeroom teacher for work without a course. Pushes
     * about the assignment go to them and their Gemini key grades it.
     */
    public static function managerId(Assignment $assignment): ?int
    {
        if ($assignment->course_id !== null) {
            $owner = $assignment->relationLoaded('course')
                ? $assignment->course?->created_by
                : DB::table('courses')->where('id', $assignment->course_id)->value('created_by');
            if ($owner !== null) {
                return (int) $owner;
            }
        }
        $teacherId = $assignment->classroom?->teacher_id;

        return $teacherId === null ? null : (int) $teacherId;
    }

    /**
     * What the teacher may see of a student (DESIGN §24.8): homeroom = a
     * homeroom teacher of a classroom (open or closed) the student is in;
     * courseIds = the teacher's own courses bound to a classroom of the
     * student (subject teacher). Null when neither.
     *
     * @return array{homeroom: bool, course_ids: list<int>}|null
     */
    public static function forStudent(User $teacher, User $student): ?array
    {
        if (! self::isSchoolTeacher($teacher) || ! $student->isStudent() || (int) $student->school_id !== (int) $teacher->school_id) {
            return null;
        }
        $rooms = DB::table('classroom_students')->where('student_id', $student->id)->select('classroom_id');
        $homeroom = DB::table('classrooms')->whereIn('id', $rooms)->where('teacher_id', $teacher->id)->exists();
        $courseIds = DB::table('course_classroom')
            ->join('courses', 'courses.id', '=', 'course_classroom.course_id')
            ->whereIn('course_classroom.classroom_id', $rooms)
            ->where('courses.created_by', $teacher->id)
            ->where('courses.school_id', $teacher->school_id)
            ->distinct()
            ->orderBy('courses.id')
            ->pluck('courses.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if (! $homeroom && $courseIds === []) {
            return null;
        }

        return ['homeroom' => $homeroom, 'course_ids' => $courseIds];
    }

    public static function denyNotHomeroom(): Response
    {
        return Response::deny('เฉพาะครูประจำชั้นของห้องนี้ที่ทำได้', self::NOT_HOMEROOM);
    }

    public static function denyNotCourseTeacher(): Response
    {
        return Response::deny('เฉพาะครูผู้สอนรายวิชานี้ที่ทำได้ ครูประจำชั้นดูได้อย่างเดียว', self::NOT_COURSE_TEACHER);
    }

    public static function isSchoolTeacher(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->school_id !== null;
    }

    /**
     * @param  Builder<Assignment>  $query
     */
    private static function managedBy(Builder $query, User $teacher): void
    {
        $query->whereIn('assignments.course_id', DB::table('courses')->select('id')
            ->where('created_by', $teacher->id)
            ->where('school_id', $teacher->school_id))
            ->whereExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                ->from('course_classroom')
                ->whereColumn('course_classroom.course_id', 'assignments.course_id')
                ->whereColumn('course_classroom.classroom_id', 'assignments.classroom_id'));
    }
}
