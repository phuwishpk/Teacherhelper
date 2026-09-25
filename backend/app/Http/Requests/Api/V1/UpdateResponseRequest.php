<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Review\ScoreRules;
use App\Models\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/responses/{id} {final_score, final_understanding,
 * final_error_types?, explanation?, reason?} (DESIGN §9.5). The score's
 * range and step depend on the question (ScoreRules, in the controller);
 * "reason is required when the score differs from ai_score" is checked under
 * the row lock (ResponseReviewer).
 */
class UpdateResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ResponsePolicy::review runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'final_score' => ['required', 'numeric', 'min:0', 'max:999'],
            'final_understanding' => ['required', Rule::in(Response::UNDERSTANDING)],
            'final_error_types' => ['sometimes', 'nullable', 'array', 'max:9'],
            'final_error_types.*' => ['string', 'distinct', Rule::in(ScoreRules::ERROR_TYPES)],
            'explanation' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'final_score.required' => 'กรุณาใส่คะแนน',
            'final_score.numeric' => 'คะแนนต้องเป็นตัวเลข',
            'final_score.min' => 'คะแนนต้องไม่ติดลบ',
            'final_score.max' => 'คะแนนมากเกินไป',
            'final_understanding.required' => 'กรุณาเลือกระดับความเข้าใจ',
            'final_understanding.in' => 'ระดับความเข้าใจต้องเป็น good, partial หรือ not_yet',
            'final_error_types.array' => 'ประเภทข้อผิดพลาดต้องเป็นรายการ',
            'final_error_types.max' => 'ประเภทข้อผิดพลาดมากเกินไป',
            'final_error_types.*.in' => 'ประเภทข้อผิดพลาดไม่ถูกต้อง',
            'final_error_types.*.distinct' => 'ประเภทข้อผิดพลาดซ้ำกัน',
            'explanation.max' => 'คำอธิบายยาวเกิน 2000 ตัวอักษร',
            'reason.max' => 'เหตุผลยาวเกิน 1000 ตัวอักษร',
        ];
    }
}
