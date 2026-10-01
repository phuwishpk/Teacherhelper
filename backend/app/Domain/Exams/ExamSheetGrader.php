<?php

namespace App\Domain\Exams;

use App\Domain\Grading\Understanding;
use App\Domain\Scans\ResponseWriter;
use App\Models\Assignment;
use App\Models\ExamSheetRead;
use App\Models\Response;
use App\Models\Scan;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\User;

/**
 * Turns the active answer-sheet pages of one submission into `responses`
 * with the server's code score (DESIGN §22.11). Runs inside the transaction
 * that holds the submission row lock, after a page was stored, confirmed or
 * given a version.
 *
 * - Each active page gets its version (ExamSheetScorer::version): a page
 *   after page 1 takes page 1's, so page 2 that arrived first is scored as
 *   soon as page 1 comes in. A version the teacher picked is kept (on a
 *   later page only while it matches page 1's).
 * - A page with a known version writes one response per ORIGINAL question
 *   (the version's question_order maps the sheet number back), exam_answer
 *   holding {sheet_no, version_no, selected (original positions), value,
 *   doubts}. A page whose version is unknown writes none; its old responses
 *   go.
 * - An answer whose source (scan, version, reading) did not change is left
 *   alone, so a second page never resets what the teacher already reviewed
 *   on the first.
 * - Answers without a doubt are reviewed at once (final = ai, reviewed_by
 *   NULL, band confident); double_mark / ambiguous_mark / invalid_number go
 *   to the review queue (band check). Every new score logs `ai_scored`
 *   (actor system) and a replaced one `rescan`, like worksheet mcq.
 */
final class ExamSheetGrader
{
    /**
     * @param  array<int, array<int, array<string, mixed>>>  $keys  ExamScanKit::keys()
     */
    public function apply(Submission $submission, Assignment $exam, array $keys, ?User $actor): void
    {
        $scans = Scan::query()
            ->where('submission_id', $submission->id)
            ->where('state', Scan::STATE_ACTIVE)
            ->with('examSheetRead')
            ->orderBy('page_no')
            ->get();

        $pageOne = null;
        $desired = [];
        foreach ($scans as $scan) {
            $read = $scan->examSheetRead;
            if (! $read instanceof ExamSheetRead) {
                continue;
            }
            $evaluation = self::evaluate($exam, $scan, $read, $pageOne, $keys);
            if ($scan->page_no === 1) {
                $pageOne = $evaluation['version_no'];
            }
            $read->forceFill([
                'version_no' => $evaluation['version_no'],
                'version_source' => $evaluation['source'],
                'doubts' => $evaluation['doubts'] === [] ? null : $evaluation['doubts'],
            ])->save();

            $versionKey = $evaluation['version_no'] === null ? [] : ($keys[$evaluation['version_no']] ?? []);
            foreach ($evaluation['items'] as $item) {
                $keyItem = $versionKey[$item['sheet_no']];
                $desired[(int) $keyItem['question_id']] = [$scan, $item, $evaluation['version_no'], $keyItem['option_order'] ?? null];
            }
        }

        $existing = Response::query()->where('submission_id', $submission->id)->get()->keyBy('question_id');
        foreach ($existing as $questionId => $response) {
            if (! isset($desired[(int) $questionId])) {
                $response->delete();
            }
        }
        $submission->channel = Submission::CHANNEL_SCAN;

        foreach ($desired as $questionId => [$scan, $item, $versionNo, $order]) {
            $answer = [
                'sheet_no' => $item['sheet_no'],
                'version_no' => $versionNo,
                'selected' => ExamScanKit::original($item['selected'], $order),
                'value' => $item['value'],
                'doubts' => $item['doubts'],
            ];
            /** @var Response|null $response */
            $response = $existing->get($questionId);
            if ($response !== null && (int) $response->scan_id === (int) $scan->id && self::sameReading($response->exam_answer, $answer)) {
                continue;
            }
            $this->write($submission, $questionId, $response, $scan, $item, $answer, $actor);
        }
    }

    /**
     * The version, score and doubts of one page, without writing anything.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $keys
     * @return array{version_no: int|null, source: string|null, doubtful: bool, items: list<array<string, mixed>>, score: float|null, max_score: float|null, doubts: list<array{sheet_no: int|null, reason: string}>}
     */
    public static function evaluate(Assignment $exam, Scan $scan, ExamSheetRead $read, ?int $pageOneVersion, array $keys): array
    {
        // A version the teacher picked is kept, except on a later page that
        // disagrees with page 1: both pages of one sheet share a version, and
        // two versions would map two sheet numbers to one question.
        $teacher = $read->version_source === ExamSheetRead::SOURCE_TEACHER && $read->version_no !== null
            && ($scan->page_no === 1 || $pageOneVersion === null || $pageOneVersion === $read->version_no);
        if ($teacher) {
            $version = ['version_no' => $read->version_no, 'source' => ExamSheetRead::SOURCE_TEACHER, 'doubtful' => false];
        } else {
            $version = ExamSheetScorer::version((int) $exam->version_count, $scan->page_no, $read->version_fill, $pageOneVersion);
        }

        $doubts = [];
        if ($version['version_no'] === null) {
            $doubts[] = ['sheet_no' => null, 'reason' => $scan->page_no > 1 ? 'version_waiting_page_one' : 'version_unknown'];

            return [...$version, 'items' => [], 'score' => null, 'max_score' => null, 'doubts' => $doubts];
        }
        if ($version['doubtful']) {
            $doubts[] = ['sheet_no' => null, 'reason' => ExamSheetScorer::VERSION_DOUBTFUL];
        }

        $result = ExamSheetScorer::scorePage($keys[$version['version_no']] ?? [], $read->rows_fill, $read->digits_fill ?? []);
        foreach ($result['items'] as $item) {
            foreach (array_intersect($item['doubts'], ExamSheetScorer::REVIEW_DOUBTS) as $reason) {
                $doubts[] = ['sheet_no' => $item['sheet_no'], 'reason' => $reason];
            }
        }

        return [...$version, ...$result, 'doubts' => $doubts];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>  $answer
     */
    private static function sameReading(?array $stored, array $answer): bool
    {
        if ($stored === null) {
            return false;
        }
        foreach (['sheet_no', 'version_no', 'selected', 'value', 'doubts'] as $field) {
            if (($stored[$field] ?? null) !== $answer[$field]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $answer
     */
    private function write(Submission $submission, int $questionId, ?Response $response, Scan $scan, array $item, array $answer, ?User $actor): void
    {
        $previous = null;
        $previousScanId = null;
        if ($response !== null) {
            $previousScanId = $response->scan_id;
            if ($response->effectiveScore() !== null) {
                $previous = [$response->effectiveScore(), $response->effectiveUnderstanding()];
            }
        } else {
            $response = new Response(['submission_id' => $submission->id, 'question_id' => $questionId]);
        }

        $max = (float) $item['max'];
        $score = (float) $item['score'];
        $understanding = Understanding::fromU($max > 0 ? $score / $max : 0.0);
        $doubtful = array_intersect($item['doubts'], ExamSheetScorer::REVIEW_DOUBTS) !== [];
        // An unreadable number (invalid_number) was marked, so it is wrong, not unanswered.
        $blank = $answer['selected'] === [] && $answer['value'] === null
            && ! in_array(ExamSheetScorer::INVALID_NUMBER, (array) $item['doubts'], true);

        $response->fill(ResponseWriter::RESET);
        $response->fill([
            'scan_id' => $scan->id,
            'submission_page_id' => null,
            'crop_path' => null,
            'final_crop_path' => null,
            'ink_ratio' => null,
            'mcq_fill' => null,
            'cnn_text' => null,
            'cnn_confidence' => null,
            'exam_answer' => $answer,
        ]);
        $response->forceFill([
            'grading_state' => Response::STATE_SCORED,
            'ai_score' => $score,
            'ai_understanding' => $understanding,
            'ai_error_types' => $blank ? ['no_answer'] : [],
            'review_priority' => $doubtful ? 1.0 : 0.0,
            'priority_band' => $doubtful ? 'check' : 'confident',
            'fuzzy_trace' => ['system' => 'exam_sheet', 'sheet_no' => $item['sheet_no'], 'doubts' => $item['doubts']],
        ]);
        if (! $doubtful) {
            $response->forceFill([
                'final_score' => $score,
                'final_understanding' => $understanding,
                'final_error_types' => $response->ai_error_types,
                'reviewed_at' => now(),
                'reviewed_by' => null,
            ]);
        }
        $response->save();

        if ($previous !== null && $previousScanId !== $scan->id) {
            ScoreEvent::create([
                'response_id' => $response->id,
                'actor' => $actor === null ? ScoreEvent::ACTOR_SYSTEM : ScoreEvent::ACTOR_TEACHER,
                'actor_user_id' => $actor?->id,
                'action' => ScoreEvent::ACTION_RESCAN,
                'old_score' => $previous[0],
                'new_score' => null,
                'old_understanding' => $previous[1],
                'new_understanding' => null,
                'reason' => "page rescanned: scan {$previousScanId} replaced by scan {$scan->id}",
            ]);
        }
        ScoreEvent::create([
            'response_id' => $response->id,
            'actor' => ScoreEvent::ACTOR_SYSTEM,
            'actor_user_id' => null,
            'action' => ScoreEvent::ACTION_AI_SCORED,
            'old_score' => null,
            'new_score' => $score,
            'old_understanding' => null,
            'new_understanding' => $understanding,
            'reason' => null,
        ]);
    }
}
