<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/skills?subject=&grade=&q= (DESIGN §9.3). `subject` is a subject
 * id or its code (ค, ว, …); `grade` 1–12; `q` matches code or name.
 */
class SkillIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // SkillPolicy::viewAny runs in the controller
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'nullable', 'string', 'max:20'],
            'grade' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
