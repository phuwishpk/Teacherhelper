<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Gradebook\GradebookAccess;
use App\Domain\Gradebook\GradebookScores;
use App\Domain\Gradebook\GradebookSettings;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\GradebookEntry;
use App\Models\GradebookItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The teacher's own score items (DESIGN §23.3, §23.11): created for one or
 * several classrooms of a course (one row per classroom), edited, deleted,
 * and their scores typed, "ยกเว้น" set, or "ให้เต็มทั้งห้อง". Items are
 * looked up among the teacher's courses and classrooms only (404).
 */
class GradebookItemController extends Controller
{
    public const MAX_POINTS = 1000;

    /**
     * POST /api/v1/courses/{id}/gradebook-items {classroom_ids[], category_id,
     * name, max_points, is_attendance?} -> 201 {data: [item]}
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $course = CourseController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $course);
        $data = $request->validate([
            'classroom_ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'classroom_ids.*' => ['distinct'],
            'category_id' => ['required', 'integer', 'min:1'],
            ...self::fieldRules(false),
        ], self::messages());
        $classrooms = [];
        foreach ($data['classroom_ids'] as $i => $classroomId) {
            $classrooms[] = GradebookAccess::openClassroom($request->user(), $course, $classroomId, "classroom_ids.{$i}");
        }
        $categoryId = self::categoryId($course, $data['category_id']);

        $items = DB::transaction(function () use ($course, $classrooms, $categoryId, $data, $request) {
            Course::query()->whereKey($course->id)->lockForUpdate()->first();

            return array_map(fn (Classroom $classroom) => GradebookItem::create([
                'course_id' => $course->id,
                'classroom_id' => $classroom->id,
                'category_id' => $categoryId,
                'name' => trim($data['name']),
                'max_points' => round((float) $data['max_points'], 2),
                'is_attendance' => (bool) ($data['is_attendance'] ?? false),
                'position' => (int) GradebookItem::query()->where('course_id', $course->id)->where('classroom_id', $classroom->id)->max('position') + 1,
                'created_by' => $request->user()->id,
            ]), $classrooms);
        });

        return response()->json(['data' => array_map(fn (GradebookItem $item) => self::payload($item), $items)], 201);
    }

    /**
     * PATCH /api/v1/gradebook-items/{id} {name?, max_points?, category_id?,
     * is_attendance?} -> {data: item}; lowering max_points below a typed score
     * is 422 errors.max_points.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $item = $this->item($request, $id);
        $data = $request->validate([
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ...self::fieldRules(true),
        ], self::messages());

        DB::transaction(function () use ($item, $data) {
            $item = GradebookItem::query()->lockForUpdate()->findOrFail($item->id);
            if (array_key_exists('name', $data)) {
                $item->name = trim($data['name']);
            }
            if (array_key_exists('category_id', $data)) {
                $item->category_id = $data['category_id'] === null ? null : self::categoryId($item->course()->firstOrFail(), $data['category_id']);
            }
            if (array_key_exists('is_attendance', $data)) {
                $item->is_attendance = (bool) $data['is_attendance'];
            }
            if (array_key_exists('max_points', $data)) {
                $max = round((float) $data['max_points'], 2);
                $highest = GradebookEntry::query()->where('gradebook_item_id', $item->id)->max('score');
                if ($highest !== null && (float) $highest > $max + 1e-9) {
                    throw ValidationException::withMessages([
                        'max_points' => 'มีคะแนนที่กรอกไว้สูงกว่านี้ ('.GradebookSettings::formatWeight((float) $highest).') แก้คะแนนก่อนลดคะแนนเต็ม',
                    ]);
                }
                $item->max_points = $max;
            }
            $item->save();
        });

        return response()->json(['data' => self::payload($item->refresh())]);
    }

    /** DELETE /api/v1/gradebook-items/{id} -> 204 (its scores go with it) */
    public function destroy(Request $request, int $id): Response
    {
        $this->item($request, $id)->delete();

        return response()->noContent();
    }

    /**
     * PUT /api/v1/gradebook-items/{id}/scores {scores: [{student_id, score?,
     * excused?}]} -> {data: {entries: [{student_id, score, excused}]}}
     */
    public function scores(Request $request, int $id): JsonResponse
    {
        $item = $this->item($request, $id);

        return response()->json(['data' => ['entries' => GradebookScores::saveForItem($item, $request->user(), $request->input('scores'))]]);
    }

    /** POST /api/v1/gradebook-items/{id}/fill-full -> {data: {filled}} */
    public function fillFull(Request $request, int $id): JsonResponse
    {
        $item = $this->item($request, $id);

        return response()->json(['data' => ['filled' => GradebookScores::fillItem($item, $request->user())]]);
    }

    /** @return array<string, mixed> */
    public static function payload(GradebookItem $item): array
    {
        return [
            'id' => $item->id,
            'course_id' => $item->course_id,
            'classroom_id' => $item->classroom_id,
            'category_id' => $item->category_id,
            'name' => $item->name,
            'max_points' => $item->max_points,
            'is_attendance' => $item->is_attendance,
            'position' => $item->position,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    /** An item of a course the teacher created, in a classroom the teacher teaches as homeroom or subject teacher (404 otherwise). */
    private function item(Request $request, int $id): GradebookItem
    {
        $teacher = $request->user();
        $item = GradebookItem::query()
            ->whereIn('course_id', CourseController::ownQuery($request)->select('id'))
            ->whereIn('classroom_id', ClassroomAccess::classrooms($teacher)->select('classrooms.id'))
            ->findOrFail($id);
        Gate::authorize('update', $item->course()->firstOrFail());

        return $item;
    }

    private static function categoryId(Course $course, mixed $categoryId): int
    {
        $exists = GradebookCategory::query()->where('course_id', $course->id)->whereKey((int) $categoryId)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['category_id' => 'ไม่พบหมวดคะแนนนี้ในรายวิชา']);
        }

        return (int) $categoryId;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private static function fieldRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:100'],
            'max_points' => [...$required, 'numeric', 'gt:0', 'max:'.self::MAX_POINTS, 'decimal:0,2'],
            'is_attendance' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    private static function messages(): array
    {
        return [
            'classroom_ids.required' => 'เลือกห้องเรียนอย่างน้อยหนึ่งห้อง',
            'category_id.required' => 'เลือกหมวดคะแนน',
            'name.required' => 'กรุณาตั้งชื่อรายการ',
            'name.max' => 'ชื่อรายการยาวไม่เกิน 100 ตัวอักษร',
            'max_points.required' => 'กรุณาใส่คะแนนเต็ม',
            'max_points.gt' => 'คะแนนเต็มต้องมากกว่า 0',
            'max_points.max' => 'คะแนนเต็มต้องไม่เกิน '.self::MAX_POINTS,
            'max_points.decimal' => 'คะแนนเต็มมีทศนิยมได้ไม่เกิน 2 ตำแหน่ง',
        ];
    }
}
