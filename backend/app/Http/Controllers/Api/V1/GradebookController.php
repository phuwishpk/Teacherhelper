<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Gradebook\ClassroomGradebook;
use App\Domain\Gradebook\GradebookAccess;
use App\Domain\Gradebook\GradebookCsv;
use App\Domain\Gradebook\GradebookOverview;
use App\Domain\Gradebook\GradebookPublisher;
use App\Domain\Gradebook\GradebookSettings;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookSpecialGrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * The gradebook of a course (DESIGN §23): settings shared by the course's
 * classrooms, and per classroom the live grid, ร/มส, publishing and the
 * CSV export. Courses are looked up among the teacher's own (404
 * otherwise, CoursePolicy on top); a classroom must be the teacher's and
 * bound to the course (422 errors.classroom_id). Every JSON answer is
 * wrapped in {data}.
 */
class GradebookController extends Controller
{
    /** GET /api/v1/gradebook/templates -> {data: [{key, name, categories: [{name, weight, is_homework_default}]}]} */
    public function templates(): JsonResponse
    {
        Gate::authorize('viewAny', Course::class);

        return response()->json(['data' => GradebookSettings::templates()]);
    }

    /**
     * GET /api/v1/gradebook/overview?academic_year=&semester= -> {data:
     * {courses: [{id, code, name, grade_level, semester, academic_year,
     * configured, category_count, classrooms: [{id, name, student_count,
     * status, empty_categories, published_at, stale, at_risk_ms_count,
     * special_counts: {ร, มส}}]}]}} for the "ตัดเกรด" page (§23.9, §23.11).
     */
    public function overview(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Course::class);
        $data = $request->validate([
            'academic_year' => ['sometimes', 'nullable', 'integer', 'min:2500', 'max:2700'],
            'semester' => ['sometimes', 'nullable', 'integer', Rule::in(Course::SEMESTERS)],
        ], [
            'academic_year.*' => 'ปีการศึกษาต้องเป็น พ.ศ.',
            'semester.*' => 'ภาคเรียนต้องเป็น 1, 2 หรือ 0 (ทั้งปี)',
        ]);
        $query = CourseController::ownQuery($request);
        if (isset($data['academic_year'])) {
            $query->where('academic_year', (int) $data['academic_year']);
        }
        if (isset($data['semester'])) {
            $query->where('semester', (int) $data['semester']);
        }

        return response()->json(['data' => ['courses' => GradebookOverview::of($request->user(), $query)]]);
    }

    /** GET /api/v1/courses/{id}/gradebook/settings -> {data: settings} */
    public function settings(Request $request, int $id): JsonResponse
    {
        $course = $this->course($request, $id, 'view');

        return response()->json(['data' => GradebookSettings::payload($course)]);
    }

    /**
     * PUT /api/v1/courses/{id}/gradebook/categories {template} | {categories:
     * [{id?, name, weight, drop_lowest?, is_homework_default?}]} -> {data: settings}.
     * 409 gradebook_configured (template on a configured course), 422
     * weights_not_100.
     */
    public function categories(Request $request, int $id): JsonResponse
    {
        $course = $this->course($request, $id, 'update');
        $data = $request->validate([
            'template' => ['required_without:categories', 'prohibits:categories', 'nullable', 'string', 'max:40'],
            'categories' => ['required_without:template'],
        ], [
            'template.required_without' => 'เลือก template หรือส่งรายการหมวด',
            'categories.required_without' => 'เลือก template หรือส่งรายการหมวด',
        ]);
        if (isset($data['template'])) {
            GradebookSettings::applyTemplate($course, $data['template']);
        } else {
            GradebookSettings::replaceCategories($course, $request->input('categories'));
        }

        return response()->json(['data' => GradebookSettings::payload($course->refresh())]);
    }

    /** PUT /api/v1/courses/{id}/gradebook/cutoffs {cutoffs: [7 integers] | null} -> {data: settings} */
    public function cutoffs(Request $request, int $id): JsonResponse
    {
        $course = $this->course($request, $id, 'update');
        if (! $request->exists('cutoffs')) {
            throw ValidationException::withMessages(['cutoffs' => 'ส่งเกณฑ์เกรด 7 ค่า หรือ null เพื่อใช้ค่าตั้งต้น']);
        }
        GradebookSettings::setCutoffs($course, $request->input('cutoffs'));

        return response()->json(['data' => GradebookSettings::payload($course->refresh())]);
    }

    /** GET /api/v1/courses/{id}/gradebook?classroom_id= -> {data: grid} (§23.11) */
    public function show(Request $request, int $id): JsonResponse
    {
        [$course, $classroom] = $this->readable($request, $id);

        return response()->json(['data' => ClassroomGradebook::of($course, $classroom)->grid()]);
    }

    /**
     * PUT /api/v1/courses/{id}/gradebook/special-grades {classroom_id,
     * student_id, special: r|ms|null, note?} -> {data: {student_id, special, note}}
     */
    public function specialGrade(Request $request, int $id): JsonResponse
    {
        $course = $this->course($request, $id, 'update');
        $classroom = GradebookAccess::openClassroom($request->user(), $course, $request->input('classroom_id'));
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'min:1'],
            'special' => ['present', 'nullable', Rule::in(GradebookSpecialGrade::SPECIALS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [
            'special.in' => 'เกรดพิเศษต้องเป็น ร (r) หรือ มส (ms)',
            'note.max' => 'หมายเหตุยาวไม่เกิน 255 ตัวอักษร',
        ]);
        $studentId = (int) $data['student_id'];
        if (! $classroom->students()->whereKey($studentId)->exists()) {
            throw ValidationException::withMessages(['student_id' => 'นักเรียนคนนี้ไม่ได้อยู่ในห้องนี้']);
        }
        $key = ['course_id' => $course->id, 'classroom_id' => $classroom->id, 'student_id' => $studentId];
        $note = isset($data['note']) ? (trim((string) $data['note']) ?: null) : null;
        DB::transaction(function () use ($key, $data, $note, $request) {
            GradebookSpecialGrade::query()->where($key)->delete();
            if ($data['special'] !== null) {
                GradebookSpecialGrade::create($key + ['special' => $data['special'], 'note' => $note, 'set_by' => $request->user()->id]);
            }
        });

        return response()->json(['data' => [
            'student_id' => $studentId,
            'special' => $data['special'],
            'note' => $data['special'] === null ? null : $note,
        ]]);
    }

    /**
     * POST /api/v1/courses/{id}/gradebook/publish {classroom_id} -> 201
     * {data: {publication_id, published_at, student_count}}. 409
     * gradebook_not_configured, 422 gradebook_incomplete (errors.categories).
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $course = $this->course($request, $id, 'update');
        $classroom = GradebookAccess::openClassroom($request->user(), $course, $request->input('classroom_id'));

        return response()->json(['data' => GradebookPublisher::publish($course, $classroom, $request->user())], 201);
    }

    /** DELETE /api/v1/courses/{id}/gradebook/publish?classroom_id= -> 204; 404 when nothing is published */
    public function withdraw(Request $request, int $id): Response
    {
        $course = $this->course($request, $id, 'update');
        $classroom = GradebookAccess::openClassroom($request->user(), $course, $request->query('classroom_id', $request->input('classroom_id')));
        GradebookPublisher::withdraw($course, $classroom);

        return response()->noContent();
    }

    /** GET /api/v1/courses/{id}/gradebook/export?classroom_id= -> text/csv (§23.8) */
    public function export(Request $request, int $id): Response
    {
        [$course, $classroom] = $this->readable($request, $id);
        GradebookAccess::assertConfigured($course);
        $csv = GradebookCsv::build(ClassroomGradebook::of($course, $classroom));
        $name = GradebookCsv::fileName($course->code, $classroom->name);
        $fallback = "gradebook-{$course->id}-{$classroom->id}.csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * The course and classroom of a read (grid, CSV): an own course and a
     * classroom it is bound to, or, for a homeroom teacher, another teacher's
     * course bound to their classroom (DESIGN §24.8: every course of the
     * class, read-only). A course bound to none of the teacher's homerooms is
     * a 404; a classroom outside the course is a 422.
     *
     * @return array{0: Course, 1: Classroom}
     */
    private function readable(Request $request, int $id): array
    {
        $teacher = $request->user();
        $course = Course::query()
            ->where('school_id', $teacher->school_id)
            ->where(fn ($q) => $q->where('created_by', $teacher->id)
                ->orWhereHas('classrooms', fn ($q) => $q->whereIn('classrooms.id', ClassroomAccess::homeroomClassrooms($teacher)->select('classrooms.id'))))
            ->findOrFail($id);
        if ($course->created_by === $teacher->id) {
            Gate::authorize('view', $course);

            return [$course, GradebookAccess::classroom($teacher, $course, $request->query('classroom_id'))];
        }
        $classroomId = $request->query('classroom_id');
        $classroom = filter_var($classroomId, FILTER_VALIDATE_INT) === false ? null : ClassroomAccess::homeroomClassrooms($teacher)
            ->whereHas('courses', fn ($q) => $q->whereKey($course->id))
            ->find((int) $classroomId);
        if ($classroom === null) {
            $message = 'เลือกห้องเรียนของคุณที่ผูกกับรายวิชานี้';

            throw new ApiException($message, 'validation_failed', 422, ['classroom_id' => [$message]]);
        }
        Gate::authorize('viewGradebook', [$course, $classroom]);

        return [$course, $classroom];
    }

    private function course(Request $request, int $id, string $ability): Course
    {
        $course = CourseController::ownQuery($request)->findOrFail($id);
        Gate::authorize($ability, $course);

        return $course;
    }
}
