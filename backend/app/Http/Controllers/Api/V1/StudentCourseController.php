<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mastery\CourseMasterySummary;
use App\Domain\Students\StudentClassrooms;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A student's own courses and roll-up (DESIGN §20.4, §20.7, §20.9, §24.11):
 * only courses bound to a classroom the student is in (open or closed), and
 * only their own values: no classmates, no class average, no ranking.
 */
class StudentCourseController extends Controller
{
    public function __construct(private readonly CourseMasterySummary $summary) {}

    /**
     * GET /api/v1/student/courses?course_id=&classroom_id= -> {data: [{id,
     * code, name, subject: {id, code, name}, grade_level, semester,
     * academic_year, classroom_ids, classroom: {id, name, academic_year,
     * closed}, classrooms: [...]}]}: the courses of every classroom of the
     * student, closed ones included (DESIGN §24.11). classrooms lists the
     * student's classrooms the course is bound to (open first, newest
     * year); classroom is the first of them.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = StudentClassrooms::filters($request);
        $rooms = StudentClassrooms::of($request->user())->keyBy('id');
        $roomIds = $rooms->keys()->all();
        if ($filters['classroom_id'] !== null) {
            $roomIds = array_values(array_intersect($roomIds, [$filters['classroom_id']]));
        }
        $courses = self::ownQuery($request, $roomIds)
            ->when($filters['course_id'] !== null, fn (Builder $q) => $q->where('courses.id', $filters['course_id']))
            ->with(['subject', 'classrooms' => fn ($q) => $q->whereIn('classrooms.id', $rooms->keys()->all())])
            ->orderByDesc('academic_year')
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $courses->map(function (Course $c) use ($rooms) {
            // The student's own classroom models, in their order (open first, newest year).
            $labels = $rooms->only($c->classrooms->modelKeys())->values()->map(fn (Classroom $room) => StudentClassrooms::label($room))->all();

            return [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'subject' => $c->subject === null ? null : ['id' => $c->subject->id, 'code' => $c->subject->code, 'name' => $c->subject->name],
                'grade_level' => $c->grade_level,
                'semester' => $c->semester,
                'academic_year' => $c->academic_year,
                'classroom_ids' => $c->classrooms->modelKeys(),
                'classroom' => $labels[0] ?? null,
                'classrooms' => $labels,
            ];
        })->all()]);
    }

    /** GET /api/v1/student/courses/{id}/mastery-summary?axis=standard|unit -> the student scope of the teacher's endpoint */
    public function summary(Request $request, int $id): JsonResponse
    {
        $course = self::ownQuery($request)->findOrFail($id);
        $input = Validator::make($request->query(), [
            'axis' => ['sometimes', 'nullable', 'string', Rule::in(CourseMasterySummary::AXES)],
        ], ['axis.in' => 'แกนต้องเป็น standard หรือ unit'])->validate();

        return response()->json(['data' => $this->summary->forStudent($course, $input['axis'] ?? CourseMasterySummary::AXIS_STANDARD, $request->user())]);
    }

    /**
     * @param  list<int>|null  $roomIds  the student's classrooms (null = all of them)
     * @return Builder<Course>
     */
    private static function ownQuery(Request $request, ?array $roomIds = null): Builder
    {
        $roomIds ??= self::roomIds($request);

        return Course::query()
            ->where('school_id', $request->user()->school_id)
            ->whereHas('classrooms', fn ($q) => $q->whereIn('classrooms.id', $roomIds));
    }

    /** @return list<int> */
    private static function roomIds(Request $request): array
    {
        return Classroom::query()
            ->whereHas('students', fn ($q) => $q->where('users.id', $request->user()->id))
            ->pluck('id')
            ->all();
    }
}
