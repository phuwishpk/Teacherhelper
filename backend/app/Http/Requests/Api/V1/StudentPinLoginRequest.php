<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/student/pin — {class_code, student_number, pin, device_name?}.
 */
class StudentPinLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'class_code' => ['required', 'string', 'max:12'],
            'student_number' => ['required', 'integer', 'min:1', 'max:255'],
            'pin' => ['required', 'string', 'digits:6'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'class_code.required' => 'กรุณากรอกรหัสห้อง',
            'student_number.required' => 'กรุณากรอกเลขที่',
            'student_number.integer' => 'เลขที่ต้องเป็นตัวเลข',
            'pin.required' => 'กรุณากรอก PIN',
            'pin.digits' => 'PIN ต้องเป็นตัวเลข 6 หลัก',
        ];
    }
}
