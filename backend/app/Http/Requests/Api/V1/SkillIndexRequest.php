<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/skills?subject=&grade=&level=&q=&tree=1 (DESIGN §9.3, §20.7).
 * `subject` is a subject id or its code (ค, ว, …); `grade` 1–12; `level`
 * one level or a comma list (e.g. indicator,sub_indicator); `q` matches
 * code or name. `tree=1` answers the matching skills with their ancestors
 * as a tree, not paginated, and needs `subject`.
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
            'subject' => ['required_if_accepted:tree', 'nullable', 'string', 'max:20'],
            'grade' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'level' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^(strand|standard|indicator|sub_indicator)(,(strand|standard|indicator|sub_indicator))*$/'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tree' => ['sometimes', 'nullable', 'boolean'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required_if_accepted' => 'เลือกวิชาก่อนดูแบบต้นไม้',
            'level.regex' => 'level ต้องเป็น strand, standard, indicator หรือ sub_indicator (คั่นด้วย ,)',
        ];
    }
}
