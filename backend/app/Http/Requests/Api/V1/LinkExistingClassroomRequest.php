<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesAfterAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /google/courses/{course_id}/link-existing {classroom_id, app_course_id?}
 * (DESIGN §24.10). app_course_id is required unless the teacher is the
 * homeroom teacher of the classroom (ClassroomImporter::linkExisting).
 */
class LinkExistingClassroomRequest extends FormRequest
{
    use ValidatesAfterAuthorization;

    public function authorize(): bool
    {
        return true; // the controller authorizes
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', 'min:1'],
            'app_course_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'classroom_id.required' => 'กรุณาเลือกห้องเรียน',
            'classroom_id.integer' => 'ห้องเรียนไม่ถูกต้อง',
            'classroom_id.min' => 'ห้องเรียนไม่ถูกต้อง',
            'app_course_id.integer' => 'รายวิชาไม่ถูกต้อง',
            'app_course_id.min' => 'รายวิชาไม่ถูกต้อง',
        ];
    }
}
