<?php

namespace App\Domain\Scans;

use App\Domain\Grading\McqGrader;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Response;
use App\Models\Scan;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\User;
use Throwable;

/**
 * Writes the answers of one active scan into `responses` (DESIGN §8.4, §9.4).
 *
 * Each (submission, question) has one row. A new scan of the page updates
 * the row in place: scan_id, crops and the phone's readings are replaced and
 * every grading field is reset, so the answer is graded again from the new
 * image. When the old row already carried a score, a `rescan` score_event
 * records what was replaced.
 *
 * Grading on upload:
 * - mcq is scored immediately from the bubble fill (§11.6) and logs
 *   `ai_scored` (actor `system`: a rule, not a model);
 * - short / show_work / open become `queued` for GradeScanJob;
 * - a region whose printed kind no longer fits the question (the teacher
 *   changed the type after printing) becomes `manual`.
 *
 * Must run inside the transaction that holds the submission row lock.
 */
final class ResponseWriter
{
    /** Fields a rescan resets: the new image has to be graded and reviewed again. */
    private const RESET = [
        'grading_state' => Response::STATE_QUEUED,
        'attempts' => 0,
        'extraction' => null,
        'fuzzy_trace' => null,
        'ai_score' => null,
        'ai_understanding' => null,
        'ai_error_types' => null,
        'review_priority' => null,
        'priority_band' => null,
        'final_score' => null,
        'final_understanding' => null,
        'final_error_types' => null,
        'explanation' => null,
        'explanation_edited' => false,
        'reviewed_by' => null,
        'reviewed_at' => null,
    ];

    /**
     * @param  list<MatchedRegion>  $regions
     * @return int number of responses left `queued` for GradeScanJob
     */
    public function write(
        Submission $submission,
        Scan $scan,
        Assignment $assignment,
        array $regions,
        CropSource $crops,
        User $actor,
    ): int {
        $queued = 0;
        $fileOps = [];

        foreach ($regions as $matched) {
            $response = Response::query()
                ->where('submission_id', $submission->id)
                ->where('question_id', $matched->question->id)
                ->first();

            $previous = null;
            $previousScanId = null;
            if ($response !== null) {
                $previousScanId = $response->scan_id;
                if ($response->effectiveScore() !== null || $response->effectiveUnderstanding() !== null) {
                    $previous = [$response->effectiveScore(), $response->effectiveUnderstanding()];
                }
            } else {
                $response = new Response([
                    'submission_id' => $submission->id,
                    'question_id' => $matched->question->id,
                ]);
            }

            $region = $matched->region;
            $isMcqRegion = $matched->kind() === 'mcq';
            $response->fill(self::RESET);
            $response->fill([
                'scan_id' => $scan->id,
                'ink_ratio' => $isMcqRegion ? null : $region->inkRatio,
                'mcq_fill' => $isMcqRegion ? self::fullFill($region->mcqFill ?? [], $matched->bubbleOptions()) : null,
                'cnn_text' => $isMcqRegion ? null : $region->cnnText,
                'cnn_confidence' => $isMcqRegion ? null : $region->cnnConfidence,
            ]);

            $mcq = $this->grade($response, $matched);
            $response->save();

            $withFinal = $matched->hasFinalAnswer() && $region->finalFile !== null;
            $response->crop_path = ScanFiles::cropPath($assignment->school_id, $assignment->id, $response->id);
            $response->final_crop_path = $withFinal
                ? ScanFiles::cropPath($assignment->school_id, $assignment->id, $response->id, true)
                : null;
            $response->save();

            $fileOps[] = [$region, false, $response->crop_path, $previousScanId === null];
            if ($withFinal) {
                $fileOps[] = [$region, true, $response->final_crop_path, $previousScanId === null];
            }

            if ($previous !== null) {
                ScoreEvent::create([
                    'response_id' => $response->id,
                    'actor' => ScoreEvent::ACTOR_TEACHER,
                    'actor_user_id' => $actor->id,
                    'action' => ScoreEvent::ACTION_RESCAN,
                    'old_score' => $previous[0],
                    'new_score' => null,
                    'old_understanding' => $previous[1],
                    'new_understanding' => null,
                    'reason' => "page rescanned: scan {$previousScanId} replaced by scan {$scan->id}",
                ]);
            }

            if ($mcq) {
                ScoreEvent::create([
                    'response_id' => $response->id,
                    'actor' => ScoreEvent::ACTOR_SYSTEM,
                    'actor_user_id' => null,
                    'action' => ScoreEvent::ACTION_AI_SCORED,
                    'old_score' => null,
                    'new_score' => $response->ai_score,
                    'old_understanding' => null,
                    'new_understanding' => $response->ai_understanding,
                    'reason' => null,
                ]);
            }

            if ($response->grading_state === Response::STATE_QUEUED) {
                $queued++;
            }
        }

        $this->storeCrops($fileOps, $crops);

        return $queued;
    }

    /**
     * Sets the grading fields for what can be decided on upload.
     *
     * @return bool true when the response was scored (mcq)
     */
    private function grade(Response $response, MatchedRegion $matched): bool
    {
        $question = $matched->question;
        $isMcqQuestion = $question->type === Question::TYPE_MCQ;
        $isMcqRegion = $matched->kind() === 'mcq';

        if ($isMcqQuestion !== $isMcqRegion) {
            $response->grading_state = Response::STATE_MANUAL;
            $response->fuzzy_trace = ['manual_reason' => 'layout_type_mismatch'];

            return false;
        }

        if (! $isMcqQuestion) {
            return false; // queued for GradeScanJob
        }

        $correct = $question->answer_key['correct'] ?? null;
        if (! is_string($correct) || ! in_array($correct, $matched->bubbleOptions(), true)) {
            $response->grading_state = Response::STATE_MANUAL;
            $response->fuzzy_trace = ['manual_reason' => 'answer_key_missing'];

            return false;
        }

        $grade = McqGrader::grade($response->mcq_fill ?? [], $correct, (float) $question->max_points);
        $response->forceFill([
            'grading_state' => Response::STATE_SCORED,
            'ai_score' => $grade->score,
            'ai_understanding' => $grade->understanding,
            'ai_error_types' => $grade->errorTypes,
            'review_priority' => $grade->reviewPriority,
            'priority_band' => $grade->priorityBand,
            'fuzzy_trace' => $grade->trace,
        ]);

        return true;
    }

    /**
     * Every printed option with its fill; options the phone did not report are 0.
     *
     * @param  array<string, float>  $fill
     * @param  list<string>  $options
     * @return array<string, float>
     */
    private static function fullFill(array $fill, array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            $out[$option] = round((float) ($fill[$option] ?? 0.0), 4);
        }

        return $out;
    }

    /**
     * Files are written after every row is saved, as the last step of the
     * transaction. If a write fails the transaction rolls back and the files
     * this call created for brand-new responses are removed again.
     *
     * @param  list<array{0: ScanRegion, 1: bool, 2: string, 3: bool}>  $fileOps
     */
    private function storeCrops(array $fileOps, CropSource $crops): void
    {
        $created = [];
        try {
            foreach ($fileOps as [$region, $final, $target, $isNew]) {
                $crops->store($region, $final, $target);
                if ($isNew) {
                    $created[] = $target;
                }
            }
        } catch (Throwable $e) {
            if ($created !== []) {
                ScanFiles::disk()->delete($created);
            }

            throw $e;
        }
    }
}
