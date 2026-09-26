<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Gemini\PracticeGenRequest;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/skills/{id}/practice-items/generate {count?} (DESIGN §9.6). */
class GeneratePracticeItemsRequest extends FormRequest
{
    public const DEFAULT_COUNT = 5;

    public function authorize(): bool
    {
        return true; // PracticeItemPolicy::generate runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'count' => ['sometimes', 'nullable', 'integer', 'min:'.PracticeGenRequest::MIN_COUNT, 'max:'.PracticeGenRequest::MAX_COUNT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'count.integer' => 'จำนวนข้อต้องเป็นตัวเลข',
            'count.min' => 'สร้างอย่างน้อย '.PracticeGenRequest::MIN_COUNT.' ข้อ',
            'count.max' => 'สร้างได้ครั้งละไม่เกิน '.PracticeGenRequest::MAX_COUNT.' ข้อ',
        ];
    }

    public function count(): int
    {
        $count = $this->validated('count');

        return $count === null || $count === '' ? self::DEFAULT_COUNT : (int) $count;
    }
}
