<?php

namespace App\Domain\Courses;

use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validation shared by the course, unit and lesson-plan endpoints and the
 * confirmed import of a read document (DESIGN §20.7): the fields of each,
 * and the ids that must belong to the teacher (their classrooms) or be
 * visible to their school (indicators and sub-indicators only, §20.2).
 */
final class CourseInputs
{
    public const MAX_TEXT = 10000;

    public const MAX_SKILLS = 300;

    public const MAX_CLASSROOMS = 50;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function courseRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'code' => [...$required, 'string', 'max:20'],
            'name' => [...$required, 'string', 'max:255'],
            'subject_id' => [...$required, 'integer', Rule::exists('subjects', 'id')],
            'grade_level' => [...$required, 'integer', 'min:1', 'max:12'],
            'semester' => ['sometimes', 'nullable', 'integer', Rule::in([0, 1, 2])],
            'academic_year' => [...$required, 'integer', 'min:2500', 'max:2700'],
            'hours' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_TEXT],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function unitRules(bool $partial): array
    {
        return [
            'title' => [...($partial ? ['sometimes', 'required'] : ['required']), 'string', 'max:255'],
            'hours' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_TEXT],
            'position' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function planRules(bool $partial): array
    {
        return [
            'title' => [...($partial ? ['sometimes', 'required'] : ['required']), 'string', 'max:255'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'hours' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2000'],
            'objectives' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_TEXT],
            'content' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_TEXT],
            'activities' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_TEXT],
            'assessment' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_TEXT],
            'taught_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'position' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'code.required' => 'กรุณากรอกรหัสวิชา',
            'code.max' => 'รหัสวิชายาวเกิน 20 ตัวอักษร',
            'name.required' => 'กรุณากรอกชื่อรายวิชา',
            'subject_id.required' => 'กรุณาเลือกกลุ่มสาระ',
            'subject_id.exists' => 'ไม่พบกลุ่มสาระนี้',
            'grade_level.required' => 'กรุณาเลือกชั้น',
            'grade_level.min' => 'ชั้นต้องเป็น ป.1–ม.6',
            'grade_level.max' => 'ชั้นต้องเป็น ป.1–ม.6',
            'semester.in' => 'ภาคเรียนต้องเป็น 1, 2 หรือ 0 (ทั้งปี)',
            'academic_year.required' => 'กรุณากรอกปีการศึกษา (พ.ศ.)',
            'academic_year.min' => 'ปีการศึกษาต้องเป็น พ.ศ.',
            'academic_year.max' => 'ปีการศึกษาต้องเป็น พ.ศ.',
            'hours.min' => 'จำนวนชั่วโมงต้องไม่ติดลบ',
            'hours.max' => 'จำนวนชั่วโมงมากเกินไป',
            'title.required' => 'กรุณากรอกชื่อ',
            'title.max' => 'ชื่อยาวเกิน 255 ตัวอักษร',
            'taught_on.date_format' => 'วันที่สอนต้องอยู่ในรูปแบบ YYYY-MM-DD',
            '*.max' => 'ข้อความยาวเกินไป',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, mixed>
     */
    public static function validate(array $input, array $rules): array
    {
        return Validator::make($input, $rules, self::messages())->validate();
    }

    /**
     * Indicator ids the teacher may use: visible to their school, level
     * indicator or sub_indicator (DESIGN §20.2).
     *
     * @return list<int>
     *
     * @throws ApiException 422 errors.{field}.{i}
     */
    public static function skillIds(User $teacher, mixed $ids, string $field = 'skill_ids'): array
    {
        self::assertIdList($ids, $field, self::MAX_SKILLS, 'ตัวชี้วัด');
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        $found = Skill::query()
            ->visibleToSchool($teacher->school_id)
            ->whereIn('level', Skill::ASSESSABLE_LEVELS)
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        foreach ($ids as $i => $id) {
            if (! in_array($id, $found, true)) {
                $message = 'ไม่พบตัวชี้วัดนี้ (เลือกได้เฉพาะตัวชี้วัดหรือทักษะย่อยของหลักสูตรหรือของโรงเรียน)';

                throw new ApiException($message, 'validation_failed', 422, ["{$field}.{$i}" => [$message]]);
            }
        }

        return $ids;
    }

    /**
     * Classroom ids of the teacher's own classrooms.
     *
     * @return list<int>
     *
     * @throws ApiException 422 errors.{field}.{i}
     */
    public static function classroomIds(User $teacher, mixed $ids, string $field = 'classroom_ids'): array
    {
        self::assertIdList($ids, $field, self::MAX_CLASSROOMS, 'ห้องเรียน');
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        $found = Classroom::query()
            ->where('teacher_id', $teacher->id)
            ->where('school_id', $teacher->school_id)
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        foreach ($ids as $i => $id) {
            if (! in_array($id, $found, true)) {
                $message = 'ไม่พบห้องเรียนนี้ในห้องที่คุณสอน';

                throw new ApiException($message, 'validation_failed', 422, ["{$field}.{$i}" => [$message]]);
            }
        }

        return $ids;
    }

    /**
     * @throws ApiException
     */
    private static function assertIdList(mixed $ids, string $field, int $max, string $what): void
    {
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new ApiException("รายการ{$what}ไม่ถูกต้อง", 'validation_failed', 422, [$field => ["รายการ{$what}ไม่ถูกต้อง"]]);
        }
        if (count($ids) > $max) {
            throw new ApiException("เลือก{$what}ได้ไม่เกิน {$max} รายการ", 'validation_failed', 422, [$field => ["เลือก{$what}ได้ไม่เกิน {$max} รายการ"]]);
        }
        foreach ($ids as $i => $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new ApiException("รหัส{$what}ไม่ถูกต้อง", 'validation_failed', 422, ["{$field}.{$i}" => ["รหัส{$what}ไม่ถูกต้อง"]]);
            }
        }
    }
}
