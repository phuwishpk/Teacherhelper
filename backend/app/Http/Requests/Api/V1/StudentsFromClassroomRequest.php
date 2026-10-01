<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Classrooms\RosterCopier;
use App\Domain\Google\ClassroomImporter;
use App\Http\Requests\Concerns\ValidatesAfterAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /classrooms/{id}/students/from-classroom {source_classroom_id,
 * student_ids[], numbering: keep|sorted, pin: keep|new} (DESIGN §24.6).
 */
class StudentsFromClassroomRequest extends FormRequest
{
    use ValidatesAfterAuthorization;

    public function authorize(): bool
    {
        return true; // ClassroomPolicy::manageStudents runs in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source_classroom_id' => ['required', 'integer', 'min:1'],
            'student_ids' => ['required', 'list', 'min:1', 'max:'.ClassroomImporter::MAX_STUDENT_NUMBER],
            'student_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'numbering' => ['required', 'string', Rule::in([RosterCopier::NUMBERING_KEEP, RosterCopier::NUMBERING_SORTED])],
            'pin' => ['required', 'string', Rule::in([RosterCopier::PIN_KEEP, RosterCopier::PIN_NEW])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_classroom_id.required' => 'กรุณาเลือกห้องต้นทาง',
            'source_classroom_id.integer' => 'ห้องต้นทางไม่ถูกต้อง',
            'student_ids.required' => 'กรุณาเลือกนักเรียนอย่างน้อยหนึ่งคน',
            'student_ids.min' => 'กรุณาเลือกนักเรียนอย่างน้อยหนึ่งคน',
            'student_ids.max' => 'เลือกนักเรียนได้ไม่เกิน '.ClassroomImporter::MAX_STUDENT_NUMBER.' คน',
            'student_ids.list' => 'student_ids ต้องเป็น array',
            'student_ids.*.integer' => 'รหัสนักเรียนไม่ถูกต้อง',
            'student_ids.*.distinct' => 'เลือกนักเรียนคนเดียวกันซ้ำ',
            'numbering.required' => 'กรุณาเลือกวิธีให้เลขที่',
            'numbering.in' => 'numbering ต้องเป็น keep หรือ sorted',
            'pin.required' => 'กรุณาเลือกว่าจะใช้ PIN เดิมหรือออก PIN ใหม่',
            'pin.in' => 'pin ต้องเป็น keep หรือ new',
        ];
    }

    /** @return list<int> */
    public function studentIds(): array
    {
        return array_map('intval', array_values($this->validated('student_ids')));
    }
}
