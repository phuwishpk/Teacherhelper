<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesAfterAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/skills/{id}/resources and PATCH /api/v1/resources/{id}
 * {title, url} (DESIGN §9.6). Only http(s) links.
 */
class StoreLearningResourceRequest extends FormRequest
{
    use ValidatesAfterAuthorization;

    public function authorize(): bool
    {
        return true; // LearningResourcePolicy runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:255'],
            'url' => [$required, 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'กรุณาใส่ชื่อลิงก์',
            'title.max' => 'ชื่อลิงก์ยาวเกิน 255 ตัวอักษร',
            'url.required' => 'กรุณาใส่ลิงก์',
            'url.url' => 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://',
            'url.max' => 'ลิงก์ยาวเกิน 2048 ตัวอักษร',
        ];
    }
}
