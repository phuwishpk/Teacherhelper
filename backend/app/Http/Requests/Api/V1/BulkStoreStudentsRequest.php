<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesAfterAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/classrooms/{id}/students — {students: [{name, student_number,
 * student_code?} | {student_id, student_number, reissue_pin?}]} (DESIGN §9.2,
 * §24.4). Up to 100 rows; student numbers and student ids unique within the
 * payload. StudentEnroller checks the codes and the existing students.
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
            'students.*' => ['array'],
            'students.*.student_id' => ['sometimes', 'integer', 'min:1', 'max:999999999999999999', 'distinct'],
            'students.*.name' => ['required_without:students.*.student_id', 'prohibits:students.*.student_id', 'string', 'max:255'],
            'students.*.student_number' => ['required', 'integer', 'min:1', 'max:255', 'distinct'],
            'students.*.student_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'students.*.reissue_pin' => ['sometimes', 'boolean'],
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
            'students.*.name.required_without' => 'กรุณากรอกชื่อนักเรียน',
            'students.*.name.prohibits' => 'เลือกนักเรียนที่มีอยู่ หรือกรอกชื่อนักเรียนใหม่ อย่างใดอย่างหนึ่ง',
            'students.*.student_id.integer' => 'รหัสนักเรียนไม่ถูกต้อง',
            'students.*.student_id.distinct' => 'เลือกนักเรียนคนเดียวกันซ้ำในรายการ',
            'students.*.student_code.max' => 'เลขประจำตัวยาวเกินไป',
            'students.*.student_number.required' => 'กรุณากรอกเลขที่',
            'students.*.student_number.integer' => 'เลขที่ต้องเป็นตัวเลข',
            'students.*.student_number.min' => 'เลขที่ต้องอยู่ระหว่าง 1–255',
            'students.*.student_number.max' => 'เลขที่ต้องอยู่ระหว่าง 1–255',
            'students.*.student_number.distinct' => 'เลขที่ซ้ำกันในรายการ',
        ];
    }
}
