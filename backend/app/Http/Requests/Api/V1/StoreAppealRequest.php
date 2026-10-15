<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/student/responses/{id}/appeal {reason?} (DESIGN §9.7). */
class StoreAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ResponsePolicy::appeal runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.string' => 'เหตุผลต้องเป็นข้อความ',
            'reason.max' => 'เหตุผลยาวเกิน 1000 ตัวอักษร',
        ];
    }
}
