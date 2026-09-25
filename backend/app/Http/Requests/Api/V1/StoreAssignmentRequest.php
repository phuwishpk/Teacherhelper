<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/assignments — {classroom_id, subject_id, title, strictness?, due_at?}
 * (DESIGN §8.3). The classroom must be one the teacher teaches.
 */
class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AssignmentPolicy::create runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $teacher = $this->user();

        return [
            'classroom_id' => [
                'required',
                'integer',
                Rule::exists('classrooms', 'id')
                    ->where('teacher_id', $teacher?->id)
                    ->where('school_id', $teacher?->school_id),
            ],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'strictness' => ['sometimes', 'nullable', Rule::in(Assignment::STRICTNESS)],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AssignmentMessages::MESSAGES + [
            'classroom_id.required' => 'กรุณาเลือกห้องเรียน',
            'classroom_id.exists' => 'ไม่พบห้องเรียนนี้ในห้องที่คุณสอน',
            'subject_id.required' => 'กรุณาเลือกวิชา',
            'subject_id.exists' => 'ไม่พบวิชานี้',
        ];
    }
}
