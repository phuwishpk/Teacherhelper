<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gradebook\GradebookPublisher;
use App\Http\Controllers\Controller;
use App\Models\GradebookPublication;
use App\Models\GradebookPublishedGrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A student's own published grades (DESIGN §23.7, §23.12): only their row
 * of the latest publication of each classroom that was not withdrawn. No
 * class average, no ranking, no classmates; the teacher's note on ร/มส and
 * the attendance warning stay with the teacher.
 */
class StudentGradeController extends Controller
{
    /**
     * GET /api/v1/student/grades -> {data: [{course: {id, code, name},
     * classroom_id, published_at, grade, special, total_rounded}]} newest first
     */
    public function index(Request $request): JsonResponse
    {
        $rows = self::currentRows($request->user()->id);

        return response()->json(['data' => $rows->map(fn (GradebookPublishedGrade $row) => [
            'course' => self::course($row->publication),
            'classroom_id' => $row->publication->classroom_id,
            'published_at' => $row->publication->published_at->toIso8601String(),
            'grade' => $row->grade,
            'special' => $row->special,
            'total_rounded' => $row->total_rounded,
        ])->values()->all()]);
    }

    /**
     * GET /api/v1/student/courses/{id}/grade -> {data: {course, classroom_id,
     * published_at, grade, special, total, total_rounded, breakdown}}; 404
     * until a grade of the course is published for the student.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $row = self::currentRows($request->user()->id, $id)->first();
        abort_if($row === null, 404);

        return response()->json(['data' => [
            'course' => self::course($row->publication),
            'classroom_id' => $row->publication->classroom_id,
            'published_at' => $row->publication->published_at->toIso8601String(),
            'grade' => $row->grade,
            'special' => $row->special,
            'total' => $row->total,
            'total_rounded' => $row->total_rounded,
            'breakdown' => $row->breakdown,
        ]]);
    }

    /**
     * The student's rows of publications that are the current one of their
     * classroom, newest first.
     *
     * @return Collection<int, GradebookPublishedGrade>
     */
    private static function currentRows(int $studentId, ?int $courseId = null): Collection
    {
        $rows = GradebookPublishedGrade::query()
            ->where('student_id', $studentId)
            ->whereHas('publication', function ($q) use ($courseId) {
                $q->whereNull('withdrawn_at');
                if ($courseId !== null) {
                    $q->where('course_id', $courseId);
                }
            })
            ->with('publication.course')
            ->get();

        $current = [];

        return $rows
            ->filter(function (GradebookPublishedGrade $row) use (&$current) {
                $p = $row->publication;
                $key = $p->course_id.':'.$p->classroom_id;
                $current[$key] ??= GradebookPublisher::current($p->course_id, $p->classroom_id)?->id;

                return $current[$key] === $p->id;
            })
            ->sortByDesc(fn (GradebookPublishedGrade $row) => $row->publication->published_at->getTimestamp())
            ->values();
    }

    /** @return array{id: int, code: string, name: string} */
    private static function course(GradebookPublication $publication): array
    {
        return ['id' => $publication->course_id, 'code' => (string) $publication->course?->code, 'name' => (string) $publication->course?->name];
    }
}
