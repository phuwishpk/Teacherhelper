<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /assignments/{id}/google-post {attach_blank_worksheet, instructions?, due_at?}
 * (DESIGN §18.6). due_at is any ISO 8601 time in the future (Classroom
 * refuses a past due date).
 */
class GooglePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AssignmentPolicy::manageGoogle runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'attach_blank_worksheet' => ['sometimes', 'boolean'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'due_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attach_blank_worksheet.boolean' => 'attach_blank_worksheet ต้องเป็น true หรือ false',
            'instructions.string' => 'คำสั่งเพิ่มเติมต้องเป็นข้อความ',
            'instructions.max' => 'คำสั่งเพิ่มเติมยาวเกิน 2000 ตัวอักษร',
            'due_at.date' => 'วันกำหนดส่งไม่ถูกต้อง',
            'due_at.after' => 'วันกำหนดส่งต้องเป็นเวลาในอนาคต',
        ];
    }

    /**
     * @return array{attach_blank_worksheet: bool, instructions: string|null, due_at: string|null}
     */
    public function postInput(): array
    {
        return [
            'attach_blank_worksheet' => $this->boolean('attach_blank_worksheet'),
            'instructions' => $this->validated('instructions'),
            'due_at' => $this->validated('due_at'),
        ];
    }
}
