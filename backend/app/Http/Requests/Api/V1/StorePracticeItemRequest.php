<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/practice-items {skill_id, answer_type, prompt_text, options?,
 * answer_key, explanation, status?}: a teacher-written item. The item
 * fields themselves are validated by PracticeItemData.
 */
class StorePracticeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PracticeItemPolicy::create runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'skill_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'skill_id.required' => 'กรุณาเลือกทักษะ',
            'skill_id.integer' => 'skill_id ต้องเป็น id ของทักษะ',
        ];
    }
}
