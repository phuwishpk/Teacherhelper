<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/classrooms/{id} — any subset of {name, grade_level, academic_year}.
 * class_code is never editable: it is printed on the login cards.
 */
class UpdateClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ClassroomPolicy::update runs in the controller
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'grade_level' => ['sometimes', 'required', 'integer', 'min:1', 'max:12'],
            'academic_year' => ['sometimes', 'required', 'integer', 'min:2500', 'max:2700'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return (new StoreClassroomRequest)->messages();
    }
}
