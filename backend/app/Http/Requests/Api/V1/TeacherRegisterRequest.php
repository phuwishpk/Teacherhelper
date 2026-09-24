<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/teacher/register — body per DESIGN §9.1: {school_code, name, email, password}.
 * No `confirmed` rule: the app checks the repeated password in its own form.
 */
class TeacherRegisterRequest extends FormRequest
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
            'school_code' => ['required', 'string', 'size:8'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'school_code.required' => 'กรุณากรอกรหัสโรงเรียน',
            'school_code.size' => 'รหัสโรงเรียนต้องมี 8 ตัวอักษร',
            'name.required' => 'กรุณากรอกชื่อ',
            'name.max' => 'ชื่อยาวเกินไป',
            'email.required' => 'กรุณากรอกอีเมล',
            'email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
            'email.unique' => 'อีเมลนี้ถูกใช้แล้ว',
            'password.required' => 'กรุณากรอกรหัสผ่าน',
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร',
        ];
    }
}
