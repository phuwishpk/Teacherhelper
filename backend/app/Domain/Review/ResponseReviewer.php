<?php

namespace App\Domain\Review;

use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\ExplanationRequests;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\RubricDraftRequest;
use App\Domain\Grading\ExplanationCache;
use App\Domain\Grading\FeedbackTemplates;
use App\Domain\Scans\SubmissionStatus;
use App\Domain\Training\TrainingSamples;
use App\Exceptions\ApiException;
use App\Models\Appeal;
use App\Models\Assignment;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The teacher's decisions on AI-graded answers (DESIGN §9.5, §13):
 *
 * - review(): PATCH /responses/{id}. The teacher's score, understanding,
 *   error types and (optionally) explanation become final_*, the answer is
 *   marked reviewed. The first edit of an AI explanation keeps Gemini's
 *   text in ai_explanation (explanation_source = teacher, §19.4); the
 *   teacher's text of a wrong answer becomes the stored explanation that
 *   identical answers reuse (ExplanationCache, §21.7). A score that
 *   differs from ai_score needs a reason. Every
 *   change of score or understanding is logged as score_events `override`
 *   (a manual answer's first score too), the data of the bias analysis. An
 *   overridden numeric answer may also become a training sample
 *   (TrainingSamples, §8.6): answer_text is the teacher's reading of the box.
 * - approveConfident(): "อนุมัติทั้งหมดที่มั่นใจ", AI values become final for
 *   every ReviewQueue::approvable() answer, logged as `bulk_approve`.
 * - regenerateExplanation(): a new `explanation` from Gemini (text only,
 *   §10.5) after the teacher changed the score or the error types; it
 *   replaces a stored AI text of that answer, never a teacher's.
 *
 * Published submissions are frozen here: after publishing a score changes
 * only through an appeal (Appeals) or a confirmed rescan.
 * Locks: submission row, then response rows (same order as ScanGrader).
 */
final class ResponseReviewer
{
    /** ai_calls.feature of "ให้ AI เขียนคำอธิบายใหม่" (DESIGN §21.8). */
    public const FEATURE_REGENERATE = 'review_regenerate';

    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly GeminiKeyResolver $keys,
        private readonly ExplanationRequests $explanations,
        private readonly TrainingSamples $samples,
        private readonly ExplanationCache $cache,
    ) {}

    /**
     * @param  array{final_score: float|int|string, final_understanding: string, final_error_types?: list<string>|null, explanation?: string|null, reason?: string|null, answer_text?: string|null}  $data
     */
    public function review(Response $response, User $teacher, array $data): Response
    {
        return DB::transaction(function () use ($response, $teacher, $data) {
            [$submission, $response] = self::lock($response);

            $newScore = round((float) $data['final_score'], 2);
            $newUnderstanding = $data['final_understanding'];
            $reason = self::cleanText($data['reason'] ?? null);
            if (ScoreRules::differs($response->ai_score, $newScore) && $response->ai_score !== null && $reason === null) {
                throw ValidationException::withMessages([
                    'reason' => ['คะแนนต่างจากที่ AI ให้ ('.ScoreRules::format($response->ai_score).') ต้องระบุเหตุผล'],
                ]);
            }

            $oldScore = $response->effectiveScore();
            $oldUnderstanding = $response->effectiveUnderstanding();

            $response->final_score = $newScore;
            $response->final_understanding = $newUnderstanding;
            $response->final_error_types = array_key_exists('final_error_types', $data) && $data['final_error_types'] !== null
                ? array_values(array_unique($data['final_error_types']))
                : ($response->final_error_types ?? $response->ai_error_types ?? []);
            if (array_key_exists('explanation', $data)) {
                $text = self::cleanText($data['explanation']);
                if ($text !== $response->explanation) {
                    // §19.4: the first edit keeps Gemini's own text in ai_explanation.
                    // (A row graded before explanation_source existed counts as the AI's.)
                    $machine = in_array($response->explanation_source, [Response::EXPLANATION_AI, Response::EXPLANATION_REUSED], true)
                        || ($response->explanation_source === null && ! $response->explanation_edited);
                    if ($machine && $response->explanation !== null && $response->ai_explanation === null) {
                        $response->ai_explanation = $response->explanation;
                    }
                    $response->explanation = $text;
                    $response->explanation_edited = true;
                    $response->explanation_source = Response::EXPLANATION_TEACHER;
                    self::clearExplanationError($response);
                    if ($text !== null && $newScore < (float) $response->question->max_points - 0.001) {
                        $this->rememberExplanation($response, $text, ExplanationCache::SOURCE_TEACHER);
                    }
                }
            }
            $response->reviewed_by = $teacher->id;
            $response->reviewed_at = now();
            $response->save();

            if (ScoreRules::differs($oldScore, $newScore) || $oldUnderstanding !== $newUnderstanding) {
                ScoreEvent::create([
                    'response_id' => $response->id,
                    'actor' => ScoreEvent::ACTOR_TEACHER,
                    'actor_user_id' => $teacher->id,
                    'action' => ScoreEvent::ACTION_OVERRIDE,
                    'old_score' => $oldScore,
                    'new_score' => $newScore,
                    'old_understanding' => $oldUnderstanding,
                    'new_understanding' => $newUnderstanding,
                    'reason' => $reason,
                ]);
            }

            // A corrected numeric reading becomes a training sample (DESIGN §8.6,
            // §12.3) when the score was overridden and the school allows it.
            $answerText = self::cleanText($data['answer_text'] ?? null);
            if (ScoreRules::differs($response->ai_score, $newScore) || $answerText !== null) {
                $label = TrainingSamples::labelForOverride($response, $response->question, $newScore, $answerText);
                if ($label !== null) {
                    $this->samples->recordCorrection($response, $label);
                }
            }

            SubmissionStatus::refresh($submission);

            return $response;
        });
    }

    /** @return int how many answers were approved */
    public function approveConfident(Assignment $assignment, User $teacher): int
    {
        $submissionIds = $assignment->submissions()
            ->where('status', '!=', Submission::STATUS_PUBLISHED)
            ->orderBy('id')
            ->pluck('id');

        $approved = 0;
        foreach ($submissionIds as $submissionId) {
            $approved += DB::transaction(function () use ($submissionId, $teacher) {
                $submission = Submission::query()->lockForUpdate()->find($submissionId);
                if ($submission === null || $submission->isPublished()) {
                    return 0;
                }
                $responses = Response::query()
                    ->where('submission_id', $submissionId)
                    ->whereNull('reviewed_at')
                    ->where('grading_state', Response::STATE_SCORED)
                    ->where('priority_band', 'confident')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $open = Appeal::query()
                    ->where('status', Appeal::STATUS_OPEN)
                    ->whereIn('response_id', $responses->modelKeys())
                    ->pluck('response_id')
                    ->flip();

                $count = 0;
                foreach ($responses as $response) {
                    if (! ReviewQueue::approvable($response, $open->has($response->id), false)) {
                        continue;
                    }
                    $response->forceFill([
                        'final_score' => $response->ai_score,
                        'final_understanding' => $response->ai_understanding,
                        'final_error_types' => $response->ai_error_types ?? [],
                        'reviewed_by' => $teacher->id,
                        'reviewed_at' => now(),
                    ])->save();
                    ScoreEvent::create([
                        'response_id' => $response->id,
                        'actor' => ScoreEvent::ACTOR_TEACHER,
                        'actor_user_id' => $teacher->id,
                        'action' => ScoreEvent::ACTION_BULK_APPROVE,
                        'old_score' => $response->ai_score,
                        'new_score' => $response->ai_score,
                        'old_understanding' => $response->ai_understanding,
                        'new_understanding' => $response->ai_understanding,
                        'reason' => null,
                    ]);
                    $count++;
                }
                if ($count > 0) {
                    SubmissionStatus::refresh($submission);
                }

                return $count;
            });
        }

        return $approved;
    }

    /**
     * A fresh explanation, stored as the answer's explanation (not marked as
     * edited by the teacher). Full marks and blank answers get the templates
     * GradeScanJob uses; anything else calls Gemini with the transcription
     * and the teacher's error types. Without any text read from the answer
     * (mcq, never extracted) there is nothing to explain from.
     */
    public function regenerateExplanation(Response $response, User $teacher): Response
    {
        $response->loadMissing(['question.rubricCriteria', 'submission.assignment.classroom']);
        DB::transaction(fn () => self::lock($response)); // published / still grading -> 409
        $question = $response->question;
        $score = $response->effectiveScore();

        if ($score !== null && $score >= (float) $question->max_points - 0.001) {
            $text = ['text' => FeedbackTemplates::praise($response->id), 'source' => Response::EXPLANATION_TEMPLATE];
        } elseif (! is_array($response->extraction) || $question->type === 'mcq') {
            throw new ApiException('ข้อนี้ไม่มีข้อความที่ AI อ่านได้ ให้ครูเขียนคำอธิบายเอง', 'explanation_unavailable', 422);
        } elseif (($response->extraction['blank'] ?? false) === true) {
            $text = ['text' => FeedbackTemplates::BLANK, 'source' => Response::EXPLANATION_TEMPLATE];
        } else {
            $text = ['text' => $this->askGemini($response), 'source' => Response::EXPLANATION_AI];
        }

        return DB::transaction(function () use ($response, $text) {
            $scanId = $response->scan_id;
            $pageId = $response->submission_page_id;
            [, $locked] = self::lock($response);
            if ($locked->scan_id !== $scanId || $locked->submission_page_id !== $pageId) {
                throw new ApiException('ข้อนี้ถูกสแกนใหม่ระหว่างนี้ เปิดข้อนี้อีกครั้ง', 'response_changed', 409);
            }
            $locked->explanation = $text['text'];
            $locked->explanation_edited = false;
            $locked->explanation_source = $text['source'];
            $locked->ai_explanation = null; // what the student sees is the AI's (or a template) again
            self::clearExplanationError($locked);
            $locked->save();
            if ($text['source'] === Response::EXPLANATION_AI) {
                $this->rememberExplanation($locked, $text['text'], ExplanationCache::SOURCE_AI, replaceAi: true);
            }

            return $locked;
        });
    }

    private function askGemini(Response $response): string
    {
        $assignment = $response->submission->assignment;
        $key = $this->keys->forTeacher($assignment?->classroom?->teacher_id);
        if ($key === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }

        $extraction = $response->extraction;
        $extraction['error_types'] = $response->final_error_types ?? $response->ai_error_types ?? ($extraction['error_types'] ?? []);
        $call = $this->explanations->forResponse(
            $response,
            $response->question,
            $response->question->rubricCriteria->all(),
            RubricDraftRequest::gradeLabel((int) $assignment?->classroom?->grade_level),
            $extraction,
            self::FEATURE_REGENERATE,
            $assignment?->id,
        );
        $outcome = $this->gateway->run(['explanation' => $call], $key)['explanation'];

        if ($outcome->status === CallOutcome::KEY_INVALID) {
            throw new ApiException('Gemini API key ใช้ไม่ได้ ตรวจ key ที่หน้าตั้งค่าแล้วลองอีกครั้ง', 'ai_key_invalid', 422);
        }
        if (! $outcome->isOk()) {
            throw new ApiException('AI เขียนคำอธิบายไม่สำเร็จ ลองใหม่อีกครั้ง หรือเขียนคำอธิบายเอง', 'ai_unavailable', 502);
        }

        return ExplanationRequests::text((array) $outcome->data);
    }

    /**
     * §21.7 item 6: the text becomes the stored explanation of this wrong
     * answer, so an identical answer to the same question reuses it. A
     * teacher's text replaces anything; a regenerated AI text replaces only
     * an AI text.
     */
    private function rememberExplanation(Response $response, string $text, string $source, bool $replaceAi = false): void
    {
        $hash = is_array($response->extraction) ? ExplanationCache::hash($response->question, $response->extraction) : null;
        if ($hash !== null) {
            $this->cache->remember($response->question_id, $hash, $text, $source, $response->id, $replaceAi);
        }
    }

    /**
     * Locks the submission, then the response, and checks both may be changed.
     *
     * @return array{0: Submission, 1: Response}
     */
    private static function lock(Response $response): array
    {
        $submission = Submission::query()->lockForUpdate()->findOrFail($response->submission_id);
        $locked = Response::query()->lockForUpdate()->findOrFail($response->id);
        $locked->setRelation('question', $response->relationLoaded('question') ? $response->question : $locked->question()->first());

        if ($submission->isPublished()) {
            throw new ApiException('เผยแพร่ผลของนักเรียนคนนี้แล้ว แก้ได้ผ่านคำขอตรวจใหม่หรือการสแกนใหม่เท่านั้น', 'submission_published', 409);
        }
        if (in_array($locked->grading_state, Response::IN_PROGRESS_STATES, true)) {
            throw new ApiException('ข้อนี้ AI ยังตรวจไม่เสร็จ รอสักครู่แล้วลองใหม่', 'response_grading', 409);
        }

        return [$submission, $locked];
    }

    private static function clearExplanationError(Response $response): void
    {
        $trace = $response->fuzzy_trace;
        if (is_array($trace) && array_key_exists('explanation_error', $trace)) {
            unset($trace['explanation_error']);
            $response->fuzzy_trace = $trace;
        }
    }

    private static function cleanText(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
