<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /google/connect {server_auth_code} (DESIGN §18.5, §18.6): the one-time
 * code google_sign_in's authorizeServer() gave the app.
 */
class GoogleConnectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // GoogleAccountPolicy runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'server_auth_code' => ['required', 'string', 'min:10', 'max:2048', 'regex:/^[\x21-\x7E]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'server_auth_code.required' => 'ไม่มีรหัสยืนยันจาก Google (server_auth_code)',
            'server_auth_code.string' => 'รหัสยืนยันจาก Google ต้องเป็นข้อความ',
            'server_auth_code.min' => 'รหัสยืนยันจาก Google ไม่ถูกต้อง',
            'server_auth_code.max' => 'รหัสยืนยันจาก Google ไม่ถูกต้อง',
            'server_auth_code.regex' => 'รหัสยืนยันจาก Google ไม่ถูกต้อง',
        ];
    }
}
