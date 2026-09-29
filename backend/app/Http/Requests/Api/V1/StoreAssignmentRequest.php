<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/assignments — {classroom_id, course_id, lesson_plan_id?,
 * title, strictness?, due_at?, mode?: worksheet|freeform, accept_late?,
 * score_only?} (DESIGN §8.3, §19.5, §20.1). The classroom must be one the
 * teacher teaches; the course must be bound to it and the lesson plan be
 * one of the course (checked in the controller). The subject comes from
 * the course (a subject_id sent by an older app is ignored).
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
            'course_id' => ['required', 'integer', 'min:1'],
            'lesson_plan_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'strictness' => ['sometimes', 'nullable', Rule::in(Assignment::STRICTNESS)],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'mode' => ['sometimes', 'nullable', Rule::in(Assignment::MODES)],
            'accept_late' => ['sometimes', 'nullable', 'boolean'],
            'score_only' => ['sometimes', 'nullable', 'boolean'],
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
            'course_id.required' => 'กรุณาเลือกรายวิชา',
            'course_id.integer' => 'รหัสรายวิชาไม่ถูกต้อง',
        ];
    }
}
