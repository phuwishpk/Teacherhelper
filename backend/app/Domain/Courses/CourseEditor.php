<?php

namespace App\Domain\Courses;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\LessonPlan;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes courses, units and lesson plans (DESIGN §20.1, §20.7). Input
 * arrays are already validated (CourseInputs); ids of classrooms and
 * indicators are checked here against the teacher. Units and lesson plans
 * keep a gap-free position per course (CoursePositions) under a lock of
 * the course row.
 *
 * - a course code is unique per (teacher, academic year, semester):
 *   422 errors.code;
 * - a course with assignments cannot be deleted, nor unbound from a
 *   classroom whose assignments use it: 409 course_in_use;
 * - a lesson plan's unit must be a unit of the same course.
 */
final class CourseEditor
{
    private const COURSE_FIELDS = ['code', 'name', 'subject_id', 'grade_level', 'semester', 'academic_year', 'hours', 'description'];

    private const UNIT_FIELDS = ['title', 'hours', 'description'];

    private const PLAN_FIELDS = ['title', 'unit_id', 'hours', 'objectives', 'content', 'activities', 'assessment', 'taught_on'];

    /**
     * @param  array<string, mixed>  $data  CourseInputs::courseRules(false)
     * @param  list<int>  $classroomIds  already checked (CourseInputs::classroomIds)
     * @param  list<int>  $skillIds  already checked (CourseInputs::skillIds)
     *
     * @throws ApiException
     */
    public function create(User $teacher, array $data, array $classroomIds = [], array $skillIds = []): Course
    {
        return DB::transaction(function () use ($teacher, $data, $classroomIds, $skillIds) {
            $attributes = self::clean($data, self::COURSE_FIELDS);
            $attributes['semester'] ??= 0;
            $this->assertCodeFree($teacher, $attributes);
            try {
                $course = Course::create($attributes + ['school_id' => $teacher->school_id, 'created_by' => $teacher->id]);
            } catch (UniqueConstraintViolationException) {
                throw self::codeTaken(); // a create of the same code committed after the check (uq_course)
            }
            $course->classrooms()->sync($classroomIds);
            $course->indicators()->sync($skillIds);

            return $course;
        });
    }

    /**
     * @param  array<string, mixed>  $data  CourseInputs::courseRules(true)
     *
     * @throws ApiException
     */
    public function update(Course $course, array $data): Course
    {
        return DB::transaction(function () use ($course, $data) {
            $course = Course::query()->lockForUpdate()->findOrFail($course->id);
            $attributes = self::clean($data, self::COURSE_FIELDS);
            if (array_key_exists('semester', $attributes)) {
                $attributes['semester'] ??= 0;
            }
            $course->fill($attributes);
            if ($course->isDirty(['code', 'academic_year', 'semester'])) {
                $this->assertCodeFree($course->creator()->firstOrFail(), $course->only(['code', 'academic_year', 'semester']), $course->id);
            }
            if ($course->isDirty('subject_id') && $course->assignments()->exists()) {
                throw new ApiException('รายวิชานี้มีการบ้านแล้ว เปลี่ยนกลุ่มสาระไม่ได้', 'course_in_use', 409, ['subject_id' => ['รายวิชานี้มีการบ้านแล้ว เปลี่ยนกลุ่มสาระไม่ได้']]);
            }
            try {
                $course->save();
            } catch (UniqueConstraintViolationException) {
                throw self::codeTaken();
            }

            return $course;
        });
    }

    /**
     * @throws ApiException 409 course_in_use
     */
    public function delete(Course $course): void
    {
        DB::transaction(function () use ($course) {
            $course = Course::query()->lockForUpdate()->findOrFail($course->id);
            if ($course->assignments()->exists()) {
                throw new ApiException('มีการบ้านผูกกับรายวิชานี้ ลบไม่ได้', 'course_in_use', 409);
            }
            $course->delete();
        });
    }

    /**
     * @param  list<int>  $classroomIds  already checked
     *
     * @throws ApiException 409 course_in_use
     */
    public function setClassrooms(Course $course, array $classroomIds): void
    {
        DB::transaction(function () use ($course, $classroomIds) {
            $course = Course::query()->lockForUpdate()->findOrFail($course->id);
            $current = $course->classrooms()->pluck('classrooms.id')->map(fn ($id) => (int) $id)->all();
            $removed = array_values(array_diff($current, $classroomIds));
            if ($removed !== []) {
                $used = Assignment::query()->where('course_id', $course->id)->whereIn('classroom_id', $removed)->pluck('classroom_id')->unique()->all();
                if ($used !== []) {
                    $names = Classroom::query()->whereIn('id', $used)->orderBy('name')->pluck('name')->implode(', ');
                    $message = "ห้อง {$names} มีการบ้านของรายวิชานี้ เอาออกไม่ได้";

                    throw new ApiException($message, 'course_in_use', 409, ['classroom_ids' => [$message]]);
                }
            }
            $course->classrooms()->sync($classroomIds);
            $course->touch();
        });
    }

    /**
     * @param  list<int>  $skillIds  already checked
     */
    public function setCourseIndicators(Course $course, array $skillIds): void
    {
        DB::transaction(function () use ($course, $skillIds) {
            $course->indicators()->sync($skillIds);
            $course->touch();
        });
    }

    /**
     * @param  array<string, mixed>  $data  CourseInputs::unitRules(false)
     * @param  list<int>  $skillIds  already checked
     */
    public function addUnit(Course $course, array $data, array $skillIds = []): Unit
    {
        return DB::transaction(function () use ($course, $data, $skillIds) {
            $course = Course::query()->lockForUpdate()->findOrFail($course->id);
            $unit = Unit::create(self::clean($data, self::UNIT_FIELDS) + [
                'course_id' => $course->id,
                'position' => CoursePositions::next(CoursePositions::UNITS, $course->id),
            ]);
            if (isset($data['position'])) {
                $unit->position = CoursePositions::move(CoursePositions::UNITS, $course->id, $unit->id, (int) $data['position']);
            }
            $unit->indicators()->sync($skillIds);

            return $unit->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  CourseInputs::unitRules(true)
     * @param  list<int>|null  $skillIds  null = keep
     */
    public function updateUnit(Unit $unit, array $data, ?array $skillIds = null): Unit
    {
        return DB::transaction(function () use ($unit, $data, $skillIds) {
            Course::query()->lockForUpdate()->findOrFail($unit->course_id);
            $unit = Unit::query()->findOrFail($unit->id);
            $unit->fill(self::clean($data, self::UNIT_FIELDS))->save();
            if (isset($data['position'])) {
                CoursePositions::move(CoursePositions::UNITS, $unit->course_id, $unit->id, (int) $data['position']);
            }
            if ($skillIds !== null) {
                $unit->indicators()->sync($skillIds);
            }

            return $unit->refresh();
        });
    }

    public function deleteUnit(Unit $unit): void
    {
        DB::transaction(function () use ($unit) {
            Course::query()->lockForUpdate()->findOrFail($unit->course_id);
            // Its lesson plans stay in the course, outside any unit (unit_id ON DELETE SET NULL).
            $unit->delete();
            CoursePositions::compact(CoursePositions::UNITS, $unit->course_id);
        });
    }

    /**
     * @param  array<string, mixed>  $data  CourseInputs::planRules(false)
     * @param  list<int>  $skillIds  already checked
     *
     * @throws ApiException 422 errors.unit_id
     */
    public function addPlan(Course $course, array $data, array $skillIds = []): LessonPlan
    {
        return DB::transaction(function () use ($course, $data, $skillIds) {
            $course = Course::query()->lockForUpdate()->findOrFail($course->id);
            $attributes = self::clean($data, self::PLAN_FIELDS);
            $this->assertUnitOf($course->id, $attributes['unit_id'] ?? null);
            $plan = LessonPlan::create($attributes + [
                'course_id' => $course->id,
                'position' => CoursePositions::next(CoursePositions::LESSON_PLANS, $course->id),
            ]);
            if (isset($data['position'])) {
                CoursePositions::move(CoursePositions::LESSON_PLANS, $course->id, $plan->id, (int) $data['position']);
            }
            $plan->indicators()->sync($skillIds);

            return $plan->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  CourseInputs::planRules(true)
     * @param  list<int>|null  $skillIds  null = keep
     *
     * @throws ApiException 422 errors.unit_id
     */
    public function updatePlan(LessonPlan $plan, array $data, ?array $skillIds = null): LessonPlan
    {
        return DB::transaction(function () use ($plan, $data, $skillIds) {
            Course::query()->lockForUpdate()->findOrFail($plan->course_id);
            $plan = LessonPlan::query()->findOrFail($plan->id);
            $attributes = self::clean($data, self::PLAN_FIELDS);
            if (array_key_exists('unit_id', $attributes)) {
                $this->assertUnitOf($plan->course_id, $attributes['unit_id']);
            }
            $plan->fill($attributes)->save();
            if (isset($data['position'])) {
                CoursePositions::move(CoursePositions::LESSON_PLANS, $plan->course_id, $plan->id, (int) $data['position']);
            }
            if ($skillIds !== null) {
                $plan->indicators()->sync($skillIds);
            }

            return $plan->refresh();
        });
    }

    public function deletePlan(LessonPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            Course::query()->lockForUpdate()->findOrFail($plan->course_id);
            // Assignments linked to it keep their course (lesson_plan_id ON DELETE SET NULL).
            $plan->delete();
            CoursePositions::compact(CoursePositions::LESSON_PLANS, $plan->course_id);
        });
    }

    /**
     * Only the listed fields that were sent, strings trimmed and '' as null.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function clean(array $data, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attributes  code, academic_year, semester
     *
     * @throws ApiException 422 errors.code
     */
    private function assertCodeFree(User $teacher, array $attributes, ?int $exceptId = null): void
    {
        $taken = Course::query()
            ->where('school_id', $teacher->school_id)
            ->where('created_by', $teacher->id)
            ->where('code', $attributes['code'])
            ->where('academic_year', $attributes['academic_year'])
            ->where('semester', $attributes['semester'] ?? 0)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
        if ($taken) {
            throw self::codeTaken();
        }
    }

    private static function codeTaken(): ApiException
    {
        $message = 'มีรายวิชารหัสนี้ในปีการศึกษาและภาคเรียนนี้แล้ว';

        return new ApiException($message, 'validation_failed', 422, ['code' => [$message]]);
    }

    /**
     * @throws ApiException 422 errors.unit_id
     */
    private function assertUnitOf(int $courseId, mixed $unitId): void
    {
        if ($unitId !== null && ! Unit::query()->where('course_id', $courseId)->whereKey((int) $unitId)->exists()) {
            throw new ApiException('ไม่พบหน่วยนี้ในรายวิชา', 'validation_failed', 422, ['unit_id' => ['ไม่พบหน่วยนี้ในรายวิชา']]);
        }
    }
}
