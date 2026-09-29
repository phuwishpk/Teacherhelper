<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Google\ClassroomImporter;
use App\Http\Requests\Concerns\ValidatesAfterAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /classrooms/import-google (DESIGN §19.9) {course_id, name, grade_level,
 * academic_year, students: [{google_user_id, student_number}],
 * removed: [google_user_id]}. Names and e-mails are never taken from here:
 * ClassroomImporter reads them from Google again.
 */
class ImportGoogleClassroomRequest extends FormRequest
{
    use ValidatesAfterAuthorization;

    /** Per request, like the Classroom roster page (a course has at most a few hundred students). */
    public const MAX_ROWS = 500;

    public function authorize(): bool
    {
        return true; // ClassroomPolicy::create runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'course_id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:100'],
            'grade_level' => ['required', 'integer', 'min:1', 'max:12'],
            'academic_year' => ['required', 'integer', 'min:2500', 'max:2700'],
            'students' => ['present', 'list', 'max:'.self::MAX_ROWS],
            'students.*' => ['required', 'array:google_user_id,student_number'],
            'students.*.google_user_id' => ['required', 'string', 'max:64', 'distinct'],
            'students.*.student_number' => ['required', 'integer', 'min:1', 'max:'.ClassroomImporter::MAX_STUDENT_NUMBER, 'distinct'],
            'removed' => ['sometimes', 'list', 'max:'.self::MAX_ROWS],
            'removed.*' => ['required', 'string', 'max:64', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'course_id.required' => 'กรุณาเลือกคอร์ส',
            'name.required' => 'กรุณากรอกชื่อห้อง',
            'name.max' => 'ชื่อห้องยาวเกิน 100 ตัวอักษร',
            'grade_level.required' => 'กรุณาเลือกระดับชั้น',
            'grade_level.min' => 'ระดับชั้นต้องอยู่ระหว่าง ป.1 (1) ถึง ม.6 (12)',
            'grade_level.max' => 'ระดับชั้นต้องอยู่ระหว่าง ป.1 (1) ถึง ม.6 (12)',
            'academic_year.required' => 'กรุณากรอกปีการศึกษา',
            'academic_year.min' => 'ปีการศึกษาต้องเป็น พ.ศ.',
            'academic_year.max' => 'ปีการศึกษาต้องเป็น พ.ศ.',
            'students.present' => 'ไม่มีรายชื่อนักเรียน (students)',
            'students.list' => 'students ต้องเป็น array',
            'students.max' => 'รายชื่อนักเรียนมากเกินไป',
            'students.*.array' => 'แต่ละแถวต้องมีแค่ google_user_id และ student_number',
            'students.*.google_user_id.required' => 'ไม่มี google_user_id',
            'students.*.google_user_id.distinct' => 'บัญชี Google เดียวกันถูกส่งมาซ้ำ',
            'students.*.student_number.required' => 'กรุณากรอกเลขที่',
            'students.*.student_number.integer' => 'เลขที่ต้องเป็นตัวเลข',
            'students.*.student_number.min' => 'เลขที่ต้องไม่น้อยกว่า 1',
            'students.*.student_number.max' => 'เลขที่ต้องไม่เกิน '.ClassroomImporter::MAX_STUDENT_NUMBER,
            'students.*.student_number.distinct' => 'เลขที่ซ้ำกัน',
            'removed.list' => 'removed ต้องเป็น array',
            'removed.*.distinct' => 'บัญชีที่เอาออกซ้ำกัน',
        ];
    }

    /**
     * @return array{course_id: string, name: string, grade_level: int, academic_year: int, students: list<array{google_user_id: string, student_number: int}>, removed: list<string>}
     */
    public function importInput(): array
    {
        return [
            'course_id' => (string) $this->validated('course_id'),
            'name' => trim((string) $this->validated('name')),
            'grade_level' => (int) $this->validated('grade_level'),
            'academic_year' => (int) $this->validated('academic_year'),
            'students' => array_map(fn (array $s) => [
                'google_user_id' => (string) $s['google_user_id'],
                'student_number' => (int) $s['student_number'],
            ], array_values($this->validated('students'))),
            'removed' => array_map('strval', array_values($this->validated('removed', []))),
        ];
    }
}
