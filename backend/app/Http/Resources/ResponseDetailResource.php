<?php

namespace App\Http\Resources;

use App\Domain\Exams\ExamAnswerView;
use App\Domain\Review\ReviewFlags;
use App\Domain\Review\ReviewQueue;
use App\Domain\Review\ScoreExplainer;
use App\Models\Appeal;
use App\Models\ClassroomStudent;
use App\Models\Response;
use App\Models\RubricCriterion;
use App\Models\ScoreEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/responses/{id} for the teacher (DESIGN §9.5, §13): one answer
 * with everything the review screen shows.
 *
 * {id, submission_id, submission_status, student: {id, name, student_number},
 *  question: {id, position, type, max_points, prompt_text, answer_key,
 *    is_numeric, rubric_criteria: [{id, criterion_id, position, description,
 *    points, is_core}]},
 *  grading_state, manual_reason, priority_band, review_priority, tab,
 *  flags[], suspicious, identity_mismatch, has_open_appeal, bulk_approvable,
 *  extraction, fuzzy_trace, why: [Thai sentences from the trace],
 *  ai_score, ai_understanding, ai_error_types,
 *  final_score, final_understanding, final_error_types,
 *  explanation, explanation_edited, explanation_error,
 *  cnn_text, cnn_confidence, ink_ratio, mcq_fill,
 *  has_crop, has_final_crop, crop_url, final_crop_url,
 *  channel, late, total_overridden, submission_page_id, page_image_url, page_mime_type,
 *  answer_box ([ymin, xmin, ymax, xmax] 0–1000 on that page, or null),
 *  answer_page (the page of the file the box is on, 1-based, or null when not known, §46),
 *  ai_explanation (Gemini's text once the teacher edited it), explanation_source,
 *  auto_rule (blank_ink "ไม่ได้ตอบ" | cnn_match "อ่านด้วย CNN" | null, §21.3),
 *  reviewed_by, reviewed_at, appeal: {...}|null, score_events: [...],
 *  exam: an answer-sheet answer of an exam (ExamAnswerView::teacher) | null}
 *
 * rubric_criteria.criterion_id is the number `extraction.criteria[].criterion_id`
 * refers to (1-based position order, ExtractionRequests); `id` is the row id.
 *
 * @mixin Response
 */
class ResponseDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Response $response */
        $response = $this->resource;
        $submission = $response->submission;
        $question = $response->question;
        $appeal = $response->appeal;
        $appealOpen = $appeal?->status === Appeal::STATUS_OPEN;
        $flags = ReviewFlags::of($response, $appealOpen);
        $criteria = $question->rubricCriteria->values();

        return [
            'id' => $response->id,
            'submission_id' => $response->submission_id,
            'submission_status' => $submission->status,
            'student' => [
                'id' => $submission->student_id,
                'name' => (string) $submission->student?->name,
                'student_number' => self::studentNumber($response),
            ],
            'question' => [
                'id' => $question->id,
                'position' => (int) $question->position,
                'type' => $question->type,
                'max_points' => (float) $question->max_points,
                'prompt_text' => $question->prompt_text,
                'answer_key' => $question->answer_key,
                'is_numeric' => (bool) $question->is_numeric,
                'rubric_criteria' => $criteria->map(fn (RubricCriterion $c, int $i) => [
                    'id' => $c->id,
                    'criterion_id' => $i + 1,
                    'position' => (int) $c->position,
                    'description' => $c->description,
                    'points' => (float) $c->points,
                    'is_core' => (bool) $c->is_core,
                ])->all(),
            ],
            'grading_state' => $response->grading_state,
            'manual_reason' => $response->manualReason(),
            // DESIGN §36.1: the code of the AI failure that left it to the teacher.
            'ai_error_code' => $response->fuzzy_trace['ai_error_code'] ?? null,
            'ai_call_id' => $response->fuzzy_trace['ai_call_id'] ?? null,
            'priority_band' => $response->priority_band,
            'review_priority' => $response->review_priority,
            'tab' => ReviewQueue::tab($response),
            'flags' => $flags,
            'suspicious' => in_array(ReviewFlags::SUSPICIOUS, $flags, true),
            'identity_mismatch' => in_array(ReviewFlags::IDENTITY_MISMATCH, $flags, true),
            'has_open_appeal' => $appealOpen,
            'bulk_approvable' => ReviewQueue::approvable($response, $appealOpen, $submission->isPublished()),
            'extraction' => $response->extraction,
            'fuzzy_trace' => $response->fuzzy_trace,
            'why' => ScoreExplainer::why($response),
            'ai_score' => $response->ai_score,
            'ai_understanding' => $response->ai_understanding,
            'ai_error_types' => $response->ai_error_types,
            'final_score' => $response->final_score,
            'final_understanding' => $response->final_understanding,
            'final_error_types' => $response->final_error_types,
            'explanation' => $response->explanation,
            'explanation_edited' => $response->explanation_edited,
            'explanation_error' => $response->explanationError(),
            'cnn_text' => $response->cnn_text,
            'cnn_confidence' => $response->cnn_confidence,
            'ink_ratio' => $response->ink_ratio,
            'mcq_fill' => $response->mcq_fill,
            'has_crop' => $response->crop_path !== null,
            'has_final_crop' => $response->final_crop_path !== null,
            'crop_url' => $response->crop_path !== null ? route('api.responses.crop', $response->id, false) : null,
            'final_crop_url' => $response->final_crop_path !== null ? route('api.responses.crop', ['id' => $response->id, 'part' => 'final'], false) : null,
            // Whole-page answers (§19.4): the file it was read from and where on it.
            'channel' => $submission->channel,
            'late' => (bool) $submission->late,
            // The total was taken from Classroom (§19.3): the app warns that changing this score clears it.
            'total_overridden' => $submission->total_override !== null,
            'submission_page_id' => $response->submission_page_id,
            'page_image_url' => self::pageImageUrl($response),
            'page_mime_type' => $response->submissionPage?->mime_type,
            'answer_box' => is_array($response->extraction['answer_box'] ?? null) ? $response->extraction['answer_box'] : null,
            'answer_page' => self::answerPage($response),
            'ai_explanation' => $response->ai_explanation,
            'explanation_source' => $response->explanation_source,
            'auto_rule' => $response->auto_rule,
            'reviewed_by' => $response->reviewed_by,
            'reviewed_at' => $response->reviewed_at?->toIso8601String(),
            'appeal' => $appeal === null ? null : AppealResource::summary($appeal),
            'exam' => ExamAnswerView::teacher($response),
            'score_events' => $response->scoreEvents
                ->sortByDesc('id')
                ->values()
                ->map(fn (ScoreEvent $e) => [
                    'id' => $e->id,
                    'actor' => $e->actor,
                    'actor_user_id' => $e->actor_user_id,
                    'action' => $e->action,
                    'old_score' => $e->old_score,
                    'new_score' => $e->new_score,
                    'old_understanding' => $e->old_understanding,
                    'new_understanding' => $e->new_understanding,
                    'reason' => $e->reason,
                    'created_at' => $e->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * The page of the handed-in file the answer box is on (DESIGN §46): what
     * Gemini said when it is within the file; a file of one page (a photo,
     * or a box read before §46 asked for the page) is on its only page.
     */
    private static function answerPage(Response $response): ?int
    {
        $pages = $response->submissionPage?->page_count;
        if ($pages === null || ! is_array($response->extraction['answer_box'] ?? null)) {
            return null;
        }
        $page = $response->extraction['answer_page'] ?? null;
        if (is_int($page)) {
            return $page >= 1 && $page <= $pages ? $page : null;
        }

        return $pages === 1 ? 1 : null;
    }

    public static function pageImageUrl(Response $response): ?string
    {
        return $response->submission_page_id !== null ? route('api.submission-pages.image', $response->submission_page_id, false) : null;
    }

    private static function studentNumber(Response $response): ?int
    {
        $classroomId = $response->submission->assignment?->classroom_id;
        $number = $classroomId === null ? null : ClassroomStudent::query()
            ->where('classroom_id', $classroomId)
            ->where('student_id', $response->submission->student_id)
            ->value('student_number');

        return $number === null ? null : (int) $number;
    }
}
