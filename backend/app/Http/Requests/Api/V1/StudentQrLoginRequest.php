<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/student/qr — {qr_token, device_name?}. The app sends the
 * bare token; the full card payload `EVL1.{token}` is accepted too.
 */
class StudentQrLoginRequest extends FormRequest
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
            'qr_token' => ['required', 'string', 'max:128'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'qr_token.required' => 'กรุณาสแกนบัตร QR',
        ];
    }
}
