<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/classrooms — {name, grade_level, academic_year} (DESIGN §8.1).
 */
class StoreClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ClassroomPolicy::create runs in the controller
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'grade_level' => ['required', 'integer', 'min:1', 'max:12'],
            'academic_year' => ['required', 'integer', 'min:2500', 'max:2700'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'กรุณากรอกชื่อห้อง',
            'name.max' => 'ชื่อห้องยาวเกิน 100 ตัวอักษร',
            'grade_level.required' => 'กรุณาเลือกระดับชั้น',
            'grade_level.min' => 'ระดับชั้นต้องอยู่ระหว่าง ป.1 (1) ถึง ม.6 (12)',
            'grade_level.max' => 'ระดับชั้นต้องอยู่ระหว่าง ป.1 (1) ถึง ม.6 (12)',
            'academic_year.required' => 'กรุณากรอกปีการศึกษา',
            'academic_year.min' => 'ปีการศึกษาต้องเป็น พ.ศ.',
            'academic_year.max' => 'ปีการศึกษาต้องเป็น พ.ศ.',
        ];
    }
}
