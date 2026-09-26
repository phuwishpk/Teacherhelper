<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /classrooms/{id}/google-roster {matches: [{google_user_id, student_id|null}]}
 * (DESIGN §18.6). One pair per account and one account per student.
 */
class GoogleRosterRequest extends FormRequest
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
            'matches' => ['present', 'list', 'max:500'],
            'matches.*' => ['required', 'array:google_user_id,student_id'],
            'matches.*.google_user_id' => ['required', 'string', 'max:64', 'distinct'],
            'matches.*.student_id' => ['present', 'nullable', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'matches.present' => 'ไม่มีรายการจับคู่ (matches)',
            'matches.list' => 'matches ต้องเป็น array',
            'matches.max' => 'รายการจับคู่มากเกินไป',
            'matches.*.required' => 'รายการจับคู่ไม่ถูกต้อง',
            'matches.*.array' => 'รายการจับคู่ต้องมีแค่ google_user_id และ student_id',
            'matches.*.google_user_id.required' => 'ไม่มี google_user_id',
            'matches.*.google_user_id.string' => 'google_user_id ต้องเป็นข้อความ',
            'matches.*.google_user_id.max' => 'google_user_id ไม่ถูกต้อง',
            'matches.*.google_user_id.distinct' => 'บัญชี Google เดียวกันถูกส่งมาซ้ำ',
            'matches.*.student_id.present' => 'ไม่มี student_id (ใช้ null ถ้าไม่จับคู่)',
            'matches.*.student_id.integer' => 'student_id ต้องเป็นตัวเลข',
            'matches.*.student_id.min' => 'student_id ไม่ถูกต้อง',
            'matches.*.student_id.distinct' => 'จับคู่นักเรียนคนเดียวกับสองบัญชีไม่ได้',
        ];
    }

    /**
     * @return list<array{google_user_id: string, student_id: int|null}>
     */
    public function matches(): array
    {
        return array_map(fn (array $m) => [
            'google_user_id' => (string) $m['google_user_id'],
            'student_id' => $m['student_id'] === null ? null : (int) $m['student_id'],
        ], array_values($this->validated('matches')));
    }
}
