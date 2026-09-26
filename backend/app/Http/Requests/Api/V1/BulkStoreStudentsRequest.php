<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesAfterAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/classrooms/{id}/students — {students: [{name, student_number}]}
 * (DESIGN §9.2). Up to 100 rows; student numbers unique within the payload.
 */
class BulkStoreStudentsRequest extends FormRequest
{
    use ValidatesAfterAuthorization;

    public const MAX_ROWS = 100;

    public function authorize(): bool
    {
        return true; // ClassroomPolicy::manageStudents runs in the controller
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'students' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'students.*.name' => ['required', 'string', 'max:255'],
            'students.*.student_number' => ['required', 'integer', 'min:1', 'max:255', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'students.required' => 'กรุณาใส่รายชื่อนักเรียนอย่างน้อย 1 คน',
            'students.max' => 'เพิ่มได้ครั้งละไม่เกิน '.self::MAX_ROWS.' คน',
            'students.*.name.required' => 'กรุณากรอกชื่อนักเรียน',
            'students.*.student_number.required' => 'กรุณากรอกเลขที่',
            'students.*.student_number.integer' => 'เลขที่ต้องเป็นตัวเลข',
            'students.*.student_number.min' => 'เลขที่ต้องอยู่ระหว่าง 1–255',
            'students.*.student_number.max' => 'เลขที่ต้องอยู่ระหว่าง 1–255',
            'students.*.student_number.distinct' => 'เลขที่ซ้ำกันในรายการ',
        ];
    }
}
