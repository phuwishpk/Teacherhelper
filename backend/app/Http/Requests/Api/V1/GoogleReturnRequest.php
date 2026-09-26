<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** POST /google-submissions/{id}/return {reason} (DESIGN §18.6). */
class GoogleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ClassroomSubmissionImportPolicy runs in the controller
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'กรุณาบอกเหตุผลที่ให้ถ่ายรูปใหม่',
            'reason.string' => 'เหตุผลต้องเป็นข้อความ',
            'reason.max' => 'เหตุผลยาวเกิน 255 ตัวอักษร',
        ];
    }
}
