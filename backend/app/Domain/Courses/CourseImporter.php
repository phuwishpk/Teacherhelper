<?php

namespace App\Domain\Courses;

use App\Exceptions\ApiException;
use App\Models\Course;
use App\Models\DocumentExtraction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * POST /courses/import (DESIGN §20.1, §20.7): what the teacher confirmed in
 * the form after a document was read, saved in one transaction.
 *
 *   {extraction_id?, course: {…course fields} | course_id,
 *    classroom_ids?[], skill_ids?[],
 *    units?: [{title, hours?, description?, skill_ids?[]}],
 *    lesson_plans?: [{title, unit_index? | unit_id?, hours?, objectives?,
 *                     content?, activities?, assessment?, skill_ids?[]}]}
 *
 * `course` creates a new course; `course_id` adds to one of the teacher's
 * courses (lesson plans read from a separate document), appending units
 * and plans after the existing ones and adding classrooms and indicators
 * to its sets. A plan names its unit by unit_index (0-based, in this
 * request's units) or unit_id (an existing unit of the course).
 * extraction_id, if sent, must be a course or lesson-plan read of the
 * teacher's school; it only ties the import to the read (nothing of it is
 * copied: the teacher's confirmed form is the source).
 */
final class CourseImporter
{
    public const MAX_UNITS = 50;

    public const MAX_PLANS = 200;

    public function __construct(private readonly CourseEditor $editor) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ApiException
     */
    public function import(User $teacher, array $input): Course
    {
        $validated = Validator::make($input, [
            'extraction_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'course_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'course' => ['sometimes', 'nullable', 'array'],
            'units' => ['sometimes', 'nullable', 'array', 'list', 'max:'.self::MAX_UNITS],
            'units.*' => ['array'],
            'lesson_plans' => ['sometimes', 'nullable', 'array', 'list', 'max:'.self::MAX_PLANS],
            'lesson_plans.*' => ['array'],
            'lesson_plans.*.unit_index' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ], [
            'units.max' => 'นำเข้าได้ไม่เกิน '.self::MAX_UNITS.' หน่วยต่อครั้ง',
            'lesson_plans.max' => 'นำเข้าได้ไม่เกิน '.self::MAX_PLANS.' แผนต่อครั้ง',
        ])->validate();

        $hasCourse = isset($validated['course']);
        $hasCourseId = isset($validated['course_id']);
        if ($hasCourse === $hasCourseId) {
            $message = 'ส่งข้อมูลรายวิชาใหม่ (course) หรือรายวิชาที่มีอยู่ (course_id) อย่างใดอย่างหนึ่ง';

            throw new ApiException($message, 'validation_failed', 422, ['course' => [$message]]);
        }
        if (isset($validated['extraction_id'])) {
            $this->assertExtraction($teacher, (int) $validated['extraction_id']);
        }

        $existing = null;
        $courseData = [];
        if ($hasCourseId) {
            $existing = Course::query()->where('school_id', $teacher->school_id)->where('created_by', $teacher->id)->find((int) $validated['course_id']);
            if ($existing === null) {
                throw new ApiException('ไม่พบรายวิชานี้', 'validation_failed', 422, ['course_id' => ['ไม่พบรายวิชานี้']]);
            }
        } else {
            $courseData = self::prefixed(fn () => CourseInputs::validate((array) $validated['course'], CourseInputs::courseRules(false, $teacher)), 'course');
        }
        $classroomIds = array_key_exists('classroom_ids', $input) ? CourseInputs::classroomIds($teacher, $input['classroom_ids']) : [];
        $skillIds = array_key_exists('skill_ids', $input) ? CourseInputs::skillIds($teacher, $input['skill_ids']) : [];

        $units = [];
        foreach (array_values((array) ($input['units'] ?? [])) as $i => $unit) {
            $units[] = [
                'data' => self::prefixed(fn () => CourseInputs::validate((array) $unit, CourseInputs::unitRules(false)), "units.{$i}"),
                'skill_ids' => array_key_exists('skill_ids', (array) $unit) ? CourseInputs::skillIds($teacher, $unit['skill_ids'], "units.{$i}.skill_ids") : [],
            ];
        }
        $plans = [];
        foreach (array_values((array) ($input['lesson_plans'] ?? [])) as $i => $plan) {
            $plan = (array) $plan;
            $unitIndex = $plan['unit_index'] ?? null;
            if ($unitIndex !== null && ! isset($units[(int) $unitIndex])) {
                throw new ApiException('ไม่พบหน่วยลำดับนี้ในรายการหน่วย', 'validation_failed', 422, ["lesson_plans.{$i}.unit_index" => ['ไม่พบหน่วยลำดับนี้ในรายการหน่วย']]);
            }
            $unitId = $plan['unit_id'] ?? null;
            if ($unitId !== null && ($unitIndex !== null || $existing === null || ! Unit::query()->where('course_id', $existing->id)->whereKey((int) $unitId)->exists())) {
                throw new ApiException('ไม่พบหน่วยนี้ในรายวิชา', 'validation_failed', 422, ["lesson_plans.{$i}.unit_id" => ['ไม่พบหน่วยนี้ในรายวิชา']]);
            }
            $data = self::prefixed(fn () => CourseInputs::validate(array_diff_key($plan, ['unit_id' => 1, 'unit_index' => 1]), CourseInputs::planRules(false)), "lesson_plans.{$i}");
            unset($data['position']);
            $plans[] = [
                'data' => $data,
                'unit_index' => $unitIndex === null ? null : (int) $unitIndex,
                'unit_id' => $unitId === null ? null : (int) $unitId,
                'skill_ids' => array_key_exists('skill_ids', $plan) ? CourseInputs::skillIds($teacher, $plan['skill_ids'], "lesson_plans.{$i}.skill_ids") : [],
            ];
        }

        return DB::transaction(function () use ($teacher, $existing, $courseData, $classroomIds, $skillIds, $units, $plans) {
            if ($existing === null) {
                $course = $this->editor->create($teacher, $courseData, $classroomIds, $skillIds);
            } else {
                $course = Course::query()->lockForUpdate()->findOrFail($existing->id);
                $course->classrooms()->syncWithoutDetaching($classroomIds);
                $course->indicators()->syncWithoutDetaching($skillIds);
                $course->touch();
            }

            $unitIds = [];
            foreach ($units as $unit) {
                $data = $unit['data'];
                unset($data['position']);
                $unitIds[] = $this->editor->addUnit($course, $data, $unit['skill_ids'])->id;
            }
            foreach ($plans as $plan) {
                $unitId = $plan['unit_index'] !== null ? $unitIds[$plan['unit_index']] : $plan['unit_id'];
                $this->editor->addPlan($course, $plan['data'] + ['unit_id' => $unitId], $plan['skill_ids']);
            }

            return $course;
        });
    }

    /**
     * @throws ApiException 422 errors.extraction_id
     */
    private function assertExtraction(User $teacher, int $id): void
    {
        $ok = DocumentExtraction::query()
            ->where('school_id', $teacher->school_id)
            ->whereIn('purpose', CourseDocumentResult::KINDS)
            ->whereKey($id)
            ->exists();
        if (! $ok) {
            throw new ApiException('ไม่พบผลการอ่านเอกสารนี้', 'validation_failed', 422, ['extraction_id' => ['ไม่พบผลการอ่านเอกสารนี้']]);
        }
    }

    /**
     * Runs a nested validation and reports its errors under $prefix
     * (e.g. units.2.title).
     *
     * @template T
     *
     * @param  callable(): T  $validate
     * @return T
     */
    private static function prefixed(callable $validate, string $prefix): mixed
    {
        try {
            return $validate();
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors["{$prefix}.{$field}"] = $messages;
            }

            throw new ApiException((string) (array_values($errors)[0][0] ?? 'ข้อมูลไม่ถูกต้อง'), 'validation_failed', 422, $errors);
        }
    }
}
