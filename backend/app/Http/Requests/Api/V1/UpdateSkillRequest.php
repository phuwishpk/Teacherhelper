<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/skills/{id} {name?, code?, grade_level?} (DESIGN §20.7):
 * the teacher who added the indicator, while no answer was observed on it.
 */
class UpdateSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // SkillPolicy::update runs in the controller
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:1000'],
            'code' => ['sometimes', 'required', 'string', 'max:40'],
            'grade_level' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'กรุณากรอกชื่อตัวชี้วัด',
            'code.required' => 'กรุณากรอกรหัส',
            'code.max' => 'รหัสยาวเกิน 40 ตัวอักษร',
        ];
    }
}
