<?php

namespace App\Http\Requests\Api\V1;

use App\Models\PracticeItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/v1/practice-items?skill=&status=&cursor= (DESIGN §9.6). */
class PracticeItemIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PracticeItemPolicy::viewAny runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'skill' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(PracticeItem::STATUSES)],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'skill.integer' => 'skill ต้องเป็น id ของทักษะ',
            'status.in' => 'status ต้องเป็น draft, approved หรือ retired',
        ];
    }
}
