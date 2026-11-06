<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Courses\CourseIndicatorIds;
use App\Domain\Students\StudentClassrooms;
use App\Http\Controllers\Controller;
use App\Http\Resources\MasteryResource;
use App\Models\Mastery;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/student/mastery?course_id=&classroom_id= (DESIGN §9.7, §14.2,
 * §24.11): the student's own mastery per skill, weakest first (by value,
 * the order of GET /student/practice), with meta.weaknesses = the three
 * lowest skill ids (§14.3 "จุดอ่อนรายคน").
 *
 * Mastery belongs to the account, so it runs on across classrooms and
 * years and a row has no classroom of its own. The filters narrow it to
 * the indicators of one of the student's courses (CourseIndicatorIds) or
 * of one of their classrooms (its courses and the questions of its
 * assignments); a course or classroom that is not the student's matches
 * nothing.
 */
class StudentMasteryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $student = $request->user();
        $filters = StudentClassrooms::filters($request);

        return response()->json(self::payload($student->id, self::allowed($student, $filters['course_id'], $filters['classroom_id'])));
    }

    /**
     * The indicators of the filters, intersected; null without a filter.
     *
     * @return list<int>|null
     */
    private static function allowed(User $student, ?int $courseId, ?int $classroomId): ?array
    {
        if ($courseId === null && $classroomId === null) {
            return null;
        }
        $rooms = DB::table('classroom_students')->where('student_id', $student->id)->select('classroom_id');
        $sets = [];
        if ($courseId !== null) {
            $ownCourse = DB::table('course_classroom')->where('course_id', $courseId)->whereIn('classroom_id', $rooms)->exists();
            $sets[] = $ownCourse ? CourseIndicatorIds::of($courseId) : [];
        }
        if ($classroomId !== null) {
            $inRoom = DB::table('classroom_students')->where('student_id', $student->id)->where('classroom_id', $classroomId)->exists();
            $sets[] = $inRoom ? self::classroomIndicatorIds($classroomId) : [];
        }

        return array_values(count($sets) === 1 ? $sets[0] : array_intersect(...$sets));
    }

    /**
     * The indicators of a classroom: those of its courses and of the
     * questions of its assignments (older work without a course included).
     *
     * @return list<int>
     */
    private static function classroomIndicatorIds(int $classroomId): array
    {
        $ids = DB::table('question_skill')
            ->whereIn('question_id', DB::table('questions')->select('id')
                ->whereIn('assignment_id', DB::table('assignments')->select('id')->where('classroom_id', $classroomId)))
            ->pluck('skill_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        foreach (DB::table('course_classroom')->where('classroom_id', $classroomId)->pluck('course_id') as $courseId) {
            $ids = array_merge($ids, CourseIndicatorIds::of((int) $courseId));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>|null  $allowed  only these indicators (a subject teacher's course, DESIGN §24.8); null = all
     * @return array{data: list<array<string, mixed>>, meta: array{available: bool, weaknesses: list<int>}}
     */
    public static function payload(int $studentId, ?array $allowed = null): array
    {
        $rows = MasteryResource::weakestFirst(Mastery::query()->where('student_id', $studentId)
            ->when($allowed !== null, fn ($q) => $q->whereIn('skill_id', $allowed === [] ? [0] : $allowed))
            ->with('skill')->get());

        return [
            'data' => array_map(fn (Mastery $m) => MasteryResource::row($m), $rows),
            'meta' => [
                'available' => true,
                'weaknesses' => array_map(fn (Mastery $m) => $m->skill_id, array_slice($rows, 0, 3)),
            ],
        ];
    }
}
