<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/skills {parent_id, name, code?, grade_level?, subject_id?}
 * (DESIGN §20.7): a teacher adds a missing indicator under a standard, or a
 * sub-indicator under an indicator. The parent decides the subject and the
 * level; the parent's checks run in the controller.
 */
class StoreSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // SkillPolicy::create runs in the controller
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['required', 'integer', 'min:1'],
            'subject_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:1000'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'grade_level' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.required' => 'เลือกมาตรฐานหรือตัวชี้วัดที่จะเพิ่มไว้ใต้',
            'name.required' => 'กรุณากรอกชื่อตัวชี้วัด',
            'name.max' => 'ชื่อตัวชี้วัดยาวเกิน 1,000 ตัวอักษร',
            'code.max' => 'รหัสยาวเกิน 40 ตัวอักษร',
            'grade_level.min' => 'ชั้นต้องเป็น ป.1–ม.6',
            'grade_level.max' => 'ชั้นต้องเป็น ป.1–ม.6',
        ];
    }
}
