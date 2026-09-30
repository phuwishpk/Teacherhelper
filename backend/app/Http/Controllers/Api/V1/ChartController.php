<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mastery\IndicatorPassRate;
use App\Domain\Mastery\IndicatorProgress;
use App\Domain\Mastery\PlanProgress;
use App\Domain\Mastery\ScoreDistribution;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The chart data of DESIGN §20.4 / §20.7 that the course roll-up
 * (MasterySummaryController) and the heatmap (MasteryController) do not
 * give: indicator progress over time (1), % passing per indicator (2),
 * the score distribution of an assignment (4) and the course plan
 * progress (5). Teachers see their own classrooms, students only
 * themselves (§20.9: GET /student/indicator-progress).
 */
class ChartController extends Controller
{
    /**
     * GET /api/v1/classrooms/{id}/indicator-pass-rate?course_id= -> {data:
     * {classroom_id, course_id, pass_threshold, student_count, indicators:
     * [{skill, assessed_students, passed_students, pass_rate}]}}
     */
    public function passRate(Request $request, int $id, IndicatorPassRate $chart): JsonResponse
    {
        $classroom = self::ownClassroom($request, $id);
        $course = self::courseOf($request, $classroom);

        return response()->json(['data' => $chart->forClassroom($classroom, $course)]);
    }

    /**
     * GET /api/v1/students/{id}/indicator-progress?skill_ids=1,2 -> {data:
     * {student_id, skill_ids, skills: [{skill, value, n_obs}], series:
     * [{skill, points: [{observed_at, date, value, score_ratio, source}]}]}}
     * for a student of the teacher's classrooms (others 404).
     */
    public function studentProgress(Request $request, int $id, IndicatorProgress $chart): JsonResponse
    {
        $teacher = $request->user();
        $student = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('school_id', $teacher->school_id)
            ->whereHas('classrooms', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->findOrFail($id);
        Gate::authorize('viewMastery', $student);

        return response()->json(['data' => $chart->forStudent($student->id, $teacher->school_id, IndicatorProgress::parseIds($request->query('skill_ids')))]);
    }

    /** GET /api/v1/student/indicator-progress?skill_ids= -> the same, for the signed-in student only. */
    public function myProgress(Request $request, IndicatorProgress $chart): JsonResponse
    {
        $student = $request->user();

        return response()->json(['data' => $chart->forStudent($student->id, $student->school_id, IndicatorProgress::parseIds($request->query('skill_ids')))]);
    }

    /**
     * GET /api/v1/assignments/{id}/score-distribution -> {data:
     * {assignment_id, max_points, published_count, scored_count, mean,
     * median, mean_ratio, median_ratio, bins: [{from_ratio, to_ratio,
     * from_points, to_points, count}]}}
     */
    public function scoreDistribution(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('viewAnalytics', $assignment);

        return response()->json(['data' => ScoreDistribution::forAssignment($assignment)]);
    }

    /**
     * GET /api/v1/courses/{id}/plan-progress?classroom_id= -> {data:
     * {course, classroom_id, summary, units: [{type, id, title, position,
     * planned, taught, assessed, taught_not_assessed, not_taught,
     * plans_total, plans_taught}]}}
     */
    public function planProgress(Request $request, int $id, PlanProgress $chart): JsonResponse
    {
        $course = CourseController::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $course);
        $input = Validator::make($request->query(), [
            'classroom_id' => ['sometimes', 'nullable', 'integer'],
        ], ['classroom_id.integer' => 'รหัสห้องเรียนไม่ถูกต้อง'])->validate();

        $classroom = null;
        if (($input['classroom_id'] ?? null) !== null) {
            $classroom = $course->classrooms()
                ->where('classrooms.teacher_id', $request->user()->id)
                ->where('classrooms.id', (int) $input['classroom_id'])
                ->first();
            if ($classroom === null) {
                throw ValidationException::withMessages(['classroom_id' => 'ห้องนี้ไม่ได้เรียนรายวิชานี้']);
            }
        }

        return response()->json(['data' => $chart->forCourse($course, $classroom)]);
    }

    public static function ownClassroom(Request $request, int $id): Classroom
    {
        $teacher = $request->user();
        $classroom = Classroom::query()
            ->where('school_id', $teacher->school_id)
            ->where('teacher_id', $teacher->id)
            ->findOrFail($id);
        Gate::authorize('viewMastery', $classroom);

        return $classroom;
    }

    /** The optional ?course_id= of a classroom chart: a course of the teacher bound to it (422 errors.course_id otherwise). */
    public static function courseOf(Request $request, Classroom $classroom): ?Course
    {
        $input = Validator::make($request->query(), [
            'course_id' => ['sometimes', 'nullable', 'integer'],
        ], ['course_id.integer' => 'รหัสรายวิชาไม่ถูกต้อง'])->validate();
        if (($input['course_id'] ?? null) === null) {
            return null;
        }
        $course = CourseController::ownQuery($request)
            ->whereHas('classrooms', fn ($q) => $q->where('classrooms.id', $classroom->id))
            ->find((int) $input['course_id']);
        if ($course === null) {
            throw ValidationException::withMessages(['course_id' => 'ห้องนี้ไม่ได้เรียนรายวิชานี้']);
        }

        return $course;
    }
}
