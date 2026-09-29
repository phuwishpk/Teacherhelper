<?php

namespace App\Http\Resources;

use App\Models\Appeal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An appeal (DESIGN §8.4, §13) with the context the teacher's list needs:
 *
 * {id, status: open|accepted|rejected, reason, teacher_note, created_at,
 *  resolved_at, resolved_by, response_id, submission_id, assignment_id,
 *  assignment_title, question: {id, position, type, max_points},
 *  question_position, max_points, final_score, final_understanding,
 *  total_overridden, student: {id, name, student_number}}
 *
 * total_overridden: the submission's total was taken from Classroom
 * (§19.3), so accepting with a new score clears it.
 *
 * final_score is the score that counts now (the teacher's). Needs
 * response.question and response.submission.{assignment, student} loaded;
 * the caller passes the student's number in the classroom.
 *
 * @mixin Appeal
 */
class AppealResource extends JsonResource
{
    public function __construct(Appeal $appeal, private readonly ?int $studentNumber = null)
    {
        parent::__construct($appeal);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Appeal $appeal */
        $appeal = $this->resource;
        $response = $appeal->response;
        $submission = $response?->submission;
        $assignment = $submission?->assignment;
        $question = $response?->question;

        return self::summary($appeal) + [
            'submission_id' => $response?->submission_id,
            'assignment_id' => $submission?->assignment_id,
            'assignment_title' => $assignment?->title,
            'question' => $question === null ? null : [
                'id' => $question->id,
                'position' => (int) $question->position,
                'type' => $question->type,
                'max_points' => (float) $question->max_points,
            ],
            'question_position' => $question === null ? null : (int) $question->position,
            'max_points' => $question === null ? null : (float) $question->max_points,
            'final_score' => $response?->effectiveScore(),
            'final_understanding' => $response?->effectiveUnderstanding(),
            'total_overridden' => $submission?->total_override !== null,
            'student' => [
                'id' => $appeal->student_id,
                'name' => (string) $submission?->student?->name,
                'student_number' => $this->studentNumber,
            ],
        ];
    }

    /**
     * The appeal's own fields (also embedded in a response or a student result).
     *
     * @return array<string, mixed>
     */
    public static function summary(Appeal $appeal): array
    {
        return [
            'id' => $appeal->id,
            'response_id' => $appeal->response_id,
            'status' => $appeal->status,
            'reason' => $appeal->reason,
            'teacher_note' => $appeal->teacher_note,
            'created_at' => $appeal->created_at?->toIso8601String(),
            'resolved_at' => $appeal->resolved_at?->toIso8601String(),
            'resolved_by' => $appeal->resolved_by,
        ];
    }
}
