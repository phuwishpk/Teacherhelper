<?php

namespace App\Http\Resources;

use App\Models\ClassroomSubmissionImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Google Classroom submission of a posted assignment (DESIGN §18.6
 * GET /assignments/{id}/google-submissions):
 *
 * {id, google_submission_id, google_user_id,
 *  student: {id, name, student_number}|null (null = account not matched yet),
 *  state: new|imported|needs_retake|returned_for_retake|graded|grade_failed
 *         |waiting_key|rejected_late|unsupported (§19.8; unsupported: last_error says why),
 *  late, attachments: [{drive_file_id, title, mime_type}], alternate_link,
 *  retake_reason, last_error, grade_pushed_at, updated_at}
 *
 * student_number is set by GoogleSubmissionSync::rows().
 *
 * @mixin ClassroomSubmissionImport
 */
class GoogleSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ClassroomSubmissionImport $row */
        $row = $this->resource;
        $student = $row->student;
        $number = $row->getAttribute('student_number');

        return [
            'id' => $row->id,
            'google_submission_id' => $row->google_submission_id,
            'google_user_id' => $row->google_user_id,
            'student' => $student === null ? null : [
                'id' => $student->id,
                'name' => $student->name,
                'student_number' => $number === null ? null : (int) $number,
            ],
            'state' => $row->state,
            'late' => (bool) $row->late,
            'attachments' => array_values($row->attachments ?? []),
            'alternate_link' => $row->alternate_link,
            'retake_reason' => $row->retake_reason,
            'last_error' => $row->last_error,
            'grade_pushed_at' => $row->grade_pushed_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
