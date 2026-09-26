<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** POST /classrooms/{id}/google-link {course_id} (DESIGN §18.6). */
class GoogleLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ClassroomPolicy::manageGoogle runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'course_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'course_id.required' => 'กรุณาเลือกคอร์สใน Google Classroom',
            'course_id.string' => 'รหัสคอร์สไม่ถูกต้อง',
            'course_id.max' => 'รหัสคอร์สไม่ถูกต้อง',
            'course_id.regex' => 'รหัสคอร์สไม่ถูกต้อง',
        ];
    }
}
