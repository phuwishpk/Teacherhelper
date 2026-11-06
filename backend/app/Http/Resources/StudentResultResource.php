<?php

namespace App\Http\Resources;

use App\Domain\Exams\ExamResult;
use App\Domain\Students\StudentClassrooms;
use App\Models\Appeal;
use App\Models\Response;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published submission as its student sees it (DESIGN §9.7). Only the
 * teacher-approved values: no AI score, extraction, trace or priority.
 *
 * {id, submission_id, assignment_id, title, subject_name,
 *  assignment: {id, title, subject: {id, code, name}|null},
 *  course: {id, code, name}|null, classroom: {id, name, academic_year, closed},
 *  total_score, total_overridden, max_score, published_at}
 *
 * course and classroom label the result in the student's combined view
 * (DESIGN §24.11); course is null for older work without a course.
 *
 * total_score is the effective total (COALESCE(total_override, total_score),
 * DESIGN §19.3); total_overridden = the teacher took the total from Google
 * Classroom, so it may differ from the sum of the questions (the app notes
 * "คะแนนรวมปรับตามที่ครูรับจาก Classroom").
 * and, for GET /student/results/{submission_id} (responses loaded):
 * retake_reason (the open Google Classroom retake request of this assignment,
 * or null, §18.2) and
 * responses: [{id, response_id, question_id, position, type, max_points,
 *   prompt_text, final_score, final_understanding, final_error_types,
 *   explanation, has_crop, has_final_crop, crop_url, final_crop_url,
 *   appeal: {id, status, reason, teacher_note, ...}|null, can_appeal}]
 *
 * max_score is the assignment's full marks (Σ questions.max_points, loaded
 * as the `max_score` attribute by the controller).
 *
 * kind is homework | exam. The detail of an exam (DESIGN §22.12, §22.15)
 * has no per-question `responses` (always []); it adds ExamResult:
 * {version_label, total, max, sections: [{title, score, max}], items: [...]
 * | null}, items only when the teacher shows the key to students.
 *
 * @mixin Submission
 */
class StudentResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Submission $submission */
        $submission = $this->resource;
        $assignment = $submission->assignment;
        $subject = $assignment?->subject;

        return [
            'id' => $submission->id,
            'submission_id' => $submission->id,
            'kind' => $assignment?->kind ?? 'homework',
            'assignment_id' => $submission->assignment_id,
            'title' => $assignment?->title,
            'subject_name' => $subject?->name,
            'assignment' => $assignment === null ? null : [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'subject' => $subject === null ? null : ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name],
            ],
            'course' => StudentClassrooms::course($assignment?->course),
            'classroom' => StudentClassrooms::label($assignment?->classroom),
            'total_score' => $submission->effectiveTotal(),
            'total_overridden' => $submission->total_override !== null,
            'max_score' => $submission->getAttribute('max_score') === null ? null : round((float) $submission->getAttribute('max_score'), 2),
            'published_at' => $submission->published_at?->toIso8601String(),
            // Detail only: the teacher asked for a new photo in Google Classroom (§18.2).
            'retake_reason' => $this->when(array_key_exists('retake_reason', $submission->getAttributes()), fn () => $submission->getAttribute('retake_reason')),
            'responses' => $this->whenLoaded('responses', fn () => $assignment?->isExam() ? [] : $submission->responses
                ->sortBy(fn (Response $r) => [(int) $r->question?->position, $r->id])
                ->values()
                ->map(fn (Response $r) => self::answer($r))
                ->all()),
            $this->mergeWhen($assignment?->isExam() === true && $submission->relationLoaded('responses'), fn () => ExamResult::forStudent($submission, $assignment)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(Response $response): array
    {
        $question = $response->question;
        $appeal = $response->appeal;

        return [
            'id' => $response->id,
            'response_id' => $response->id,
            'question_id' => $response->question_id,
            'position' => (int) $question->position,
            'type' => $question->type,
            'max_points' => (float) $question->max_points,
            'prompt_text' => $question->prompt_text,
            'final_score' => $response->effectiveScore(),
            'final_understanding' => $response->effectiveUnderstanding(),
            'final_error_types' => $response->final_error_types ?? [],
            'explanation' => $response->explanation,
            'has_crop' => $response->crop_path !== null,
            'has_final_crop' => $response->final_crop_path !== null,
            'crop_url' => $response->crop_path !== null ? route('api.responses.crop', $response->id, false) : null,
            'final_crop_url' => $response->final_crop_path !== null ? route('api.responses.crop', ['id' => $response->id, 'part' => 'final'], false) : null,
            'page_image_url' => ResponseDetailResource::pageImageUrl($response),
            'appeal' => $appeal instanceof Appeal ? AppealResource::summary($appeal) : null,
            'can_appeal' => $appeal === null,
        ];
    }
}
