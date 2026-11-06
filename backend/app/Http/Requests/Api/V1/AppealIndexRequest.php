<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Appeal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/v1/appeals?status=&assignment_id=&cursor= (DESIGN §9.5), cursor-paginated. */
class AppealIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AppealPolicy::viewAny runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in([Appeal::STATUS_OPEN, Appeal::STATUS_ACCEPTED, Appeal::STATUS_REJECTED])],
            'assignment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['status.in' => 'status ต้องเป็น open, accepted หรือ rejected'];
    }
}
