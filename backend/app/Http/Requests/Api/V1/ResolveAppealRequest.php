<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Appeal;
use App\Models\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/appeals/{id} {status: accepted|rejected, teacher_note?,
 * final_score?, final_understanding?} (DESIGN §9.5). A new score only comes
 * with `accepted`; its range and step are checked against the question
 * under the lock (Appeals::resolve).
 */
class ResolveAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AppealPolicy::resolve runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([Appeal::STATUS_ACCEPTED, Appeal::STATUS_REJECTED])],
            'teacher_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'final_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999', 'prohibited_unless:status,accepted'],
            'final_understanding' => ['sometimes', 'nullable', Rule::in(Response::UNDERSTANDING), 'prohibited_unless:status,accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'กรุณาเลือกว่ารับหรือปฏิเสธคำขอ',
            'status.in' => 'status ต้องเป็น accepted หรือ rejected',
            'teacher_note.max' => 'หมายเหตุยาวเกิน 1000 ตัวอักษร',
            'final_score.numeric' => 'คะแนนต้องเป็นตัวเลข',
            'final_score.min' => 'คะแนนต้องไม่ติดลบ',
            'final_score.max' => 'คะแนนมากเกินไป',
            'final_score.prohibited_unless' => 'เปลี่ยนคะแนนได้เฉพาะเมื่อรับคำขอ',
            'final_understanding.in' => 'ระดับความเข้าใจต้องเป็น good, partial หรือ not_yet',
            'final_understanding.prohibited_unless' => 'เปลี่ยนระดับความเข้าใจได้เฉพาะเมื่อรับคำขอ',
        ];
    }
}
