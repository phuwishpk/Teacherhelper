<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Review\ReviewQueue;
use App\Models\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/v1/assignments/{id}/review-queue?band=&cursor=&per_page= (DESIGN §9.5). */
class ReviewQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AssignmentPolicy::review runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'band' => ['sometimes', 'nullable', Rule::in(Response::BANDS)],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:300'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.ReviewQueue::MAX_PAGE_SIZE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'band.in' => 'band ต้องเป็น check, look หรือ confident',
            'per_page.integer' => 'per_page ต้องเป็นจำนวนเต็ม',
            'per_page.min' => 'per_page ต้องอย่างน้อย 1',
            'per_page.max' => 'per_page ต้องไม่เกิน '.ReviewQueue::MAX_PAGE_SIZE,
        ];
    }
}
