<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Exams\ExamVersions;
use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/assignments — {classroom_id, course_id, lesson_plan_id?,
 * title, strictness?, due_at?, mode?: worksheet|freeform, accept_late?,
 * score_only?, kind?: homework|exam, grading_method?: app|manual,
 * version_count?, duration_minutes?, show_key_to_students?,
 * manual_full_marks?, gradebook_category_id?, excluded_from_grade?} (DESIGN
 * §8.3, §19.5, §20.1, §22.15, §23.3). The exam fields
 * are refused on homework; an exam needs due_at (the exam date) and a
 * manual exam manual_full_marks. The classroom must be one the
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
            // The exam date decides when a missing score counts as 0 (DESIGN §22.2, §23.4).
            'due_at' => ['required_if:kind,exam', 'nullable', 'date'],
            'mode' => ['sometimes', 'nullable', Rule::in(Assignment::MODES)],
            'accept_late' => ['sometimes', 'nullable', 'boolean'],
            'score_only' => ['sometimes', 'nullable', 'boolean'],
            // Exams (DESIGN §22.1, §22.15): kind is set at creation only.
            'kind' => ['sometimes', 'nullable', Rule::in(Assignment::KINDS)],
            'grading_method' => ['sometimes', 'nullable', 'prohibited_unless:kind,exam', Rule::in(Assignment::GRADING_METHODS)],
            'version_count' => ['sometimes', 'nullable', 'prohibited_unless:kind,exam', 'integer', 'min:1', 'max:'.ExamVersions::maxVersions()],
            'duration_minutes' => ['sometimes', 'nullable', 'prohibited_unless:kind,exam', 'integer', 'min:1', 'max:'.AssignmentMessages::MAX_DURATION_MINUTES],
            'show_key_to_students' => ['sometimes', 'nullable', 'prohibited_unless:kind,exam', 'boolean'],
            'manual_full_marks' => ['nullable', 'prohibited_unless:kind,exam', 'required_if:grading_method,manual', 'numeric', 'gt:0', 'max:'.AssignmentMessages::MAX_MANUAL_FULL_MARKS, 'decimal:0,2'],
            // Gradebook (DESIGN §23.3): checked against the course in AssignmentCategories.
            'gradebook_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'excluded_from_grade' => ['sometimes', 'nullable', 'boolean'],
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
