<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesAfterAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/student/practice/{item_id}/attempts {answer} (DESIGN §9.7). */
class PracticeAttemptRequest extends FormRequest
{
    use ValidatesAfterAuthorization;

    public const MAX_ANSWER = 500;

    public function authorize(): bool
    {
        return true; // the controller finds only approved items of the student's school
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'answer' => ['required', 'string', 'max:'.self::MAX_ANSWER],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answer.required' => 'กรุณาพิมพ์คำตอบ',
            'answer.max' => 'คำตอบยาวเกิน '.self::MAX_ANSWER.' ตัวอักษร',
        ];
    }
}
