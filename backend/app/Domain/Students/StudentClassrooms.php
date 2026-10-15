<?php

namespace App\Domain\Students;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The classrooms of one student across the school (DESIGN §24.11): every
 * classroom the account is enrolled in, open or closed, and the shared
 * labels and filters of the student endpoints. A student in two classrooms
 * sees both in one view; each item carries its classroom label
 * {id, name, academic_year, closed} so the app can say "ป.5/1 · 2569" and
 * fold closed classrooms ("ห้องเก่า") away.
 */
final class StudentClassrooms
{
    /**
     * The student's classrooms: open first, then the newest academic year,
     * then by name and id.
     *
     * @return Collection<int, Classroom>
     */
    public static function of(User $student): Collection
    {
        return Classroom::query()
            ->whereIn('id', DB::table('classroom_students')->select('classroom_id')->where('student_id', $student->id))
            ->get(['id', 'school_id', 'teacher_id', 'name', 'academic_year', 'closed_at'])
            ->sortBy(fn (Classroom $c) => [$c->isClosed() ? 1 : 0, -(int) $c->academic_year, (string) $c->name, $c->id])
            ->values();
    }

    /**
     * @return array{id: int, name: string, academic_year: int, closed: bool}|null
     */
    public static function label(?Classroom $classroom): ?array
    {
        if ($classroom === null) {
            return null;
        }

        return [
            'id' => $classroom->id,
            'name' => (string) $classroom->name,
            'academic_year' => (int) $classroom->academic_year,
            'closed' => $classroom->isClosed(),
        ];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    public static function course(?Course $course): ?array
    {
        return $course === null ? null : ['id' => $course->id, 'code' => (string) $course->code, 'name' => (string) $course->name];
    }

    /**
     * The ?course_id= / ?classroom_id= filters every student list takes
     * (422 when not an integer). A filter outside the student's own
     * classes simply matches nothing.
     *
     * @return array{course_id: int|null, classroom_id: int|null}
     */
    public static function filters(Request $request): array
    {
        $input = Validator::make($request->query(), [
            'course_id' => ['sometimes', 'nullable', 'integer'],
            'classroom_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'course_id.integer' => 'รหัสรายวิชาไม่ถูกต้อง',
            'classroom_id.integer' => 'รหัสห้องเรียนไม่ถูกต้อง',
        ])->validate();

        return [
            'course_id' => isset($input['course_id']) ? (int) $input['course_id'] : null,
            'classroom_id' => isset($input['classroom_id']) ? (int) $input['classroom_id'] : null,
        ];
    }
}
