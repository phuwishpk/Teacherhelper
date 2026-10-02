<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/teacher/register — body per DESIGN §9.1: {school_id?, name, email, password},
 * plus google_link_ticket? from a 404 google_not_linked of POST /auth/google (DESIGN §24.9.5).
 * school_code? is still accepted from older app builds (it no longer gates sign-up:
 * an admin's approval does); TeacherAuthController::resolveSchool picks the school.
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
            'school_id' => ['sometimes', 'nullable', 'integer', 'exists:schools,id'],
            'school_code' => ['sometimes', 'nullable', 'string', 'size:8'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'google_link_ticket' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'school_id.integer' => 'ไม่พบโรงเรียนที่เลือก',
            'school_id.exists' => 'ไม่พบโรงเรียนที่เลือก',
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
