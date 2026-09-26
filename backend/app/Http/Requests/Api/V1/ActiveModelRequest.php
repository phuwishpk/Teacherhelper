<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ModelVersion;
use Illuminate\Foundation\Http\FormRequest;

/** GET /api/v1/ml/models/active?name=digit_crnn (DESIGN §9.8). */
class ActiveModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'name ใช้ได้เฉพาะ a–z, 0–9 และ _',
        ];
    }

    public function name(): string
    {
        $name = $this->validated('name');

        return $name === null || $name === '' ? ModelVersion::DIGIT_CRNN : (string) $name;
    }
}
