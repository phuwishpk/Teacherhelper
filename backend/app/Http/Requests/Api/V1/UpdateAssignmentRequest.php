<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/assignments/{id} — {title?, strictness?, due_at?, status?,
 * mode?, accept_late?, score_only?, course_id?, lesson_plan_id?}. status accepts `closed` (close) and
 * `draft` (reopen). `ready` is reached only through POST
 * /assignments/{id}/layout (worksheet, checks the rubrics) or POST
 * /assignments/{id}/answer-key/approve (freeform, DESIGN §19.5). mode
 * changes only while the assignment is a draft without a layout or a
 * submission (checked in the controller).
 */
class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AssignmentPolicy::update runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'strictness' => ['sometimes', 'required', Rule::in(Assignment::STRICTNESS)],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'required', Rule::in([Assignment::STATUS_DRAFT, Assignment::STATUS_CLOSED])],
            'mode' => ['sometimes', 'required', Rule::in(Assignment::MODES)],
            'accept_late' => ['sometimes', 'required', 'boolean'],
            'score_only' => ['sometimes', 'required', 'boolean'],
            'course_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'lesson_plan_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AssignmentMessages::MESSAGES + [
            'status.in' => 'เปลี่ยนสถานะได้เป็น draft หรือ closed เท่านั้น (สถานะพร้อมพิมพ์ได้จากการสร้าง layout)',
        ];
    }
}
