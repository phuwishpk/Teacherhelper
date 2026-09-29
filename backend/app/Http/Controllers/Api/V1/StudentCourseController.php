<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mastery\CourseMasterySummary;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A student's own courses and roll-up (DESIGN §20.4, §20.7, §20.9): only
 * courses bound to a classroom the student is in, and only their own
 * values: no classmates, no class average, no ranking.
 */
class StudentCourseController extends Controller
{
    public function __construct(private readonly CourseMasterySummary $summary) {}

    /**
     * GET /api/v1/student/courses -> {data: [{id, code, name, subject:
     * {id, code, name}, grade_level, semester, academic_year, classroom_ids}]}
     */
    public function index(Request $request): JsonResponse
    {
        $roomIds = self::roomIds($request);
        $courses = self::ownQuery($request)
            ->with(['subject', 'classrooms' => fn ($q) => $q->whereIn('classrooms.id', $roomIds)])
            ->orderByDesc('academic_year')
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $courses->map(fn (Course $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'subject' => $c->subject === null ? null : ['id' => $c->subject->id, 'code' => $c->subject->code, 'name' => $c->subject->name],
            'grade_level' => $c->grade_level,
            'semester' => $c->semester,
            'academic_year' => $c->academic_year,
            'classroom_ids' => $c->classrooms->modelKeys(),
        ])->all()]);
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

    /** @return Builder<Course> */
    private static function ownQuery(Request $request): Builder
    {
        $roomIds = self::roomIds($request);

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
