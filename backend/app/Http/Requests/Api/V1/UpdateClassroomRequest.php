<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/classrooms/{id} — any subset of {name, grade_level, academic_year,
 * auto_share_analysis} (DESIGN §20.5: new analysis texts go to students without approval).
 * class_code is never editable: it is printed on the login cards.
 */
class UpdateClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ClassroomPolicy::update runs in the controller
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'grade_level' => ['sometimes', 'required', 'integer', 'min:1', 'max:12'],
            'academic_year' => ['sometimes', 'required', 'integer', 'min:2500', 'max:2700'],
            'auto_share_analysis' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return (new StoreClassroomRequest)->messages() + [
            'auto_share_analysis.required' => 'ระบุว่าจะแชร์การวิเคราะห์ให้นักเรียนอัตโนมัติหรือไม่',
            'auto_share_analysis.boolean' => 'auto_share_analysis ต้องเป็น true หรือ false',
        ];
    }
}
