<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /me/ai-key {gemini_api_key}. Google API keys are ~40 characters of
 * letters, digits, "-" and "_"; the range is loose on purpose so a new key
 * format still passes to the real check (models.list).
 */
class UpdateAiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // TeacherApiKeyPolicy runs in the controller
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('gemini_api_key'))) {
            $this->merge(['gemini_api_key' => trim($this->input('gemini_api_key'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gemini_api_key' => ['required', 'string', 'min:20', 'max:200', 'regex:/^[A-Za-z0-9._\-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gemini_api_key.required' => 'กรุณาใส่ Gemini API key',
            'gemini_api_key.string' => 'Gemini API key ต้องเป็นข้อความ',
            'gemini_api_key.min' => 'Gemini API key สั้นเกินไป ตรวจว่าคัดลอกมาครบ',
            'gemini_api_key.max' => 'Gemini API key ยาวเกินไป',
            'gemini_api_key.regex' => 'Gemini API key มีตัวอักษรที่ใช้ไม่ได้ (ห้ามมีช่องว่าง)',
        ];
    }
}
