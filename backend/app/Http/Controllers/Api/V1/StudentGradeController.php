<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gradebook\StudentPublishedGrades;
use App\Domain\Students\StudentClassrooms;
use App\Http\Controllers\Controller;
use App\Models\GradebookPublishedGrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A student's own published grades (DESIGN §23.7, §23.12, §24.11): only
 * their row of the latest publication of each (course, classroom) that was
 * not withdrawn, in every classroom they are or were in (closed ones
 * included, read-only). No class average, no ranking, no classmates; the
 * teacher's note on ร/มส and the attendance warning stay with the teacher.
 */
class StudentGradeController extends Controller
{
    /**
     * GET /api/v1/student/grades?course_id=&classroom_id= -> {data:
     * [{course: {id, code, name}, classroom_id, classroom: {id, name,
     * academic_year, closed}, published_at, grade, special, total_rounded}]}
     * newest first
     */
    public function index(Request $request): JsonResponse
    {
        $filters = StudentClassrooms::filters($request);
        $rows = StudentPublishedGrades::current($request->user()->id, $filters['course_id'], $filters['classroom_id']);

        return response()->json(['data' => $rows->map(fn (GradebookPublishedGrade $row) => self::head($row) + [
            'grade' => $row->grade,
            'special' => $row->special,
            'total_rounded' => $row->total_rounded,
        ])->values()->all()]);
    }

    /**
     * GET /api/v1/student/courses/{id}/grade?classroom_id= -> {data: {course,
     * classroom_id, classroom, published_at, grade, special, total,
     * total_rounded, breakdown}}; the newest when the course was published
     * in two of the student's classrooms (classroom_id picks one); 404 until
     * a grade of the course is published for the student.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $filters = StudentClassrooms::filters($request);
        $row = StudentPublishedGrades::current($request->user()->id, $id, $filters['classroom_id'])->first();
        abort_if($row === null, 404);

        return response()->json(['data' => self::head($row) + [
            'grade' => $row->grade,
            'special' => $row->special,
            'total' => $row->total,
            'total_rounded' => $row->total_rounded,
            'breakdown' => $row->breakdown,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function head(GradebookPublishedGrade $row): array
    {
        $publication = $row->publication;

        return [
            'course' => ['id' => $publication->course_id, 'code' => (string) $publication->course?->code, 'name' => (string) $publication->course?->name],
            'classroom_id' => $publication->classroom_id,
            'classroom' => StudentClassrooms::label($publication->classroom),
            'published_at' => $publication->published_at->toIso8601String(),
        ];
    }
}
