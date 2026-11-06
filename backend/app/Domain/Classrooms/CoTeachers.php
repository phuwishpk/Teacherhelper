<?php

namespace App\Domain\Classrooms;

use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\ClassroomTeacher;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Co-teaching by invitation (DESIGN §29.13.4, replacing the course requests
 * of §24.7): the owner invites a colleague by e-mail, the colleague accepts
 * and becomes a subject teacher of the classroom (ClassroomAccess), then
 * binds their own courses to it. Teachers come from different workspaces,
 * so nothing here looks at schools.
 */
final class CoTeachers
{
    public function __construct(private readonly CourseRequests $bindings) {}

    /**
     * POST /classrooms/{id}/teachers {email}.
     *
     * @throws ApiException 422 validation_failed (own e-mail), 409 invitation_exists
     */
    public function invite(User $owner, Classroom $classroom, string $email): ClassroomTeacher
    {
        $email = self::normalize($email);
        if ($email === self::normalize((string) $owner->email)) {
            $message = 'เชิญอีเมลของตัวเองไม่ได้';

            throw new ApiException($message, 'validation_failed', 422, ['email' => [$message]]);
        }
        $existing = ClassroomTeacher::query()->where('classroom_id', $classroom->id)->where('email', $email)->first();
        if ($existing !== null) {
            if ($existing->status !== ClassroomTeacher::STATUS_DECLINED) {
                throw new ApiException('เชิญอีเมลนี้ไว้แล้ว', 'invitation_exists', 409);
            }
            // Declined once: asked again.
            $existing->forceFill(['status' => ClassroomTeacher::STATUS_PENDING, 'decided_at' => null, 'teacher_id' => null, 'invited_by' => $owner->id])->save();

            return $existing;
        }

        return ClassroomTeacher::create([
            'classroom_id' => $classroom->id,
            'email' => $email,
            'invited_by' => $owner->id,
            'status' => ClassroomTeacher::STATUS_PENDING,
        ]);
    }

    /**
     * GET /classrooms/{id}/teachers: every row with the teacher who accepted
     * and the courses of theirs bound to the classroom.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(Classroom $classroom): array
    {
        return ClassroomTeacher::query()
            ->with('teacher:id,name')
            ->where('classroom_id', $classroom->id)
            ->orderByRaw("case status when 'accepted' then 0 when 'pending' then 1 else 2 end")
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ClassroomTeacher $row) => self::payload($row, $classroom))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public static function payload(ClassroomTeacher $row, Classroom $classroom): array
    {
        $courses = $row->teacher_id === null ? collect() : $classroom->courses()
            ->where('courses.created_by', $row->teacher_id)
            ->orderBy('courses.code')
            ->get(['courses.id', 'courses.code', 'courses.name']);

        return [
            'id' => $row->id,
            'classroom_id' => $row->classroom_id,
            'email' => $row->email,
            'status' => $row->status,
            'teacher' => $row->teacher === null ? null : ['id' => $row->teacher->id, 'name' => $row->teacher->name],
            'courses' => $courses->map(fn (Course $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all(),
            'invited_at' => $row->created_at?->toIso8601String(),
            'decided_at' => $row->decided_at?->toIso8601String(),
        ];
    }

    /**
     * DELETE /classrooms/{id}/teachers/{row}: withdraws an invitation or
     * removes a co-teacher, unbinding their courses first.
     *
     * @throws ApiException 409 course_in_use
     */
    public function remove(Classroom $classroom, ClassroomTeacher $row): void
    {
        DB::transaction(function () use ($classroom, $row) {
            if ($row->status === ClassroomTeacher::STATUS_ACCEPTED && $row->teacher_id !== null) {
                foreach ($classroom->courses()->where('courses.created_by', $row->teacher_id)->get() as $course) {
                    $this->bindings->unbind($classroom, $course);
                }
            }
            $row->delete();
        });
    }

    /**
     * GET /classroom-invitations: the pending invitations addressed to the
     * teacher's e-mail, for open classrooms.
     *
     * @return Collection<int, ClassroomTeacher>
     */
    public function incoming(User $teacher): Collection
    {
        return ClassroomTeacher::query()
            ->with(['classroom.teacher:id,name'])
            ->where('status', ClassroomTeacher::STATUS_PENDING)
            ->where('email', self::normalize((string) $teacher->email))
            ->whereHas('classroom', fn ($q) => $q->whereNull('closed_at'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    public static function incomingPayload(ClassroomTeacher $row): array
    {
        $classroom = $row->classroom;

        return [
            'id' => $row->id,
            'status' => $row->status,
            'classroom' => $classroom === null ? null : [
                'id' => $classroom->id,
                'name' => $classroom->name,
                'grade_level' => $classroom->grade_level,
                'academic_year' => $classroom->academic_year,
            ],
            'owner' => $classroom?->teacher === null ? null : ['id' => $classroom->teacher->id, 'name' => $classroom->teacher->name],
            'invited_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * A pending invitation addressed to the teacher, else 404.
     *
     * @throws ModelNotFoundException
     */
    public static function invitationFor(User $teacher, int $id): ClassroomTeacher
    {
        return ClassroomTeacher::query()
            ->with(['classroom.teacher:id,name'])
            ->where('status', ClassroomTeacher::STATUS_PENDING)
            ->where('email', self::normalize((string) $teacher->email))
            ->findOrFail($id);
    }

    /** @throws ApiException 409 classroom_closed */
    public function accept(User $teacher, ClassroomTeacher $row): ClassroomTeacher
    {
        if ($row->classroom !== null) {
            ClosedClassrooms::assertOpen($row->classroom);
        }
        $row->forceFill([
            'teacher_id' => $teacher->id,
            'status' => ClassroomTeacher::STATUS_ACCEPTED,
            'decided_at' => now(),
        ])->save();

        return $row;
    }

    public function decline(User $teacher, ClassroomTeacher $row): ClassroomTeacher
    {
        $row->forceFill(['status' => ClassroomTeacher::STATUS_DECLINED, 'decided_at' => now()])->save();

        return $row;
    }

    /** The teacher accepted an invitation to the classroom. */
    public static function coTeaches(User $teacher, Classroom $classroom): bool
    {
        return ClassroomTeacher::query()
            ->where('classroom_id', $classroom->id)
            ->where('teacher_id', $teacher->id)
            ->where('status', ClassroomTeacher::STATUS_ACCEPTED)
            ->exists();
    }

    /** Ids of the classrooms the teacher co-teaches (a subquery). */
    public static function coTeacherClassroomIds(User $teacher): QueryBuilder
    {
        return DB::table('classroom_teachers')
            ->select('classroom_id')
            ->where('teacher_id', $teacher->id)
            ->where('status', ClassroomTeacher::STATUS_ACCEPTED);
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
