<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/v1/assignments?classroom_id=&status= (DESIGN §9.3), cursor-paginated. */
class AssignmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AssignmentPolicy::viewAny runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'classroom_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', Rule::in([Assignment::STATUS_DRAFT, Assignment::STATUS_READY, Assignment::STATUS_CLOSED])],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
