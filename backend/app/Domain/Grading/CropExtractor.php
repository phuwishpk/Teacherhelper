<?php

namespace App\Domain\Grading;

use App\Domain\Gemini\BatchExtractionRequests;
use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\ExtractionRequests;
use App\Domain\Gemini\GeminiCall;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiImage;
use App\Domain\Gemini\GeminiKey;
use App\Models\Question;
use App\Models\Response;
use App\Models\RubricCriterion;

/**
 * Extraction of the crop path, one call per student page (DESIGN §21.4):
 *
 * 1. the answers of one scan go to Gemini together in one `extract_batch`
 *    call (at most services.gemini.page_max_questions per call; a lone answer
 *    uses the single `extract` prompt directly);
 * 2. an answer the batch left out or answered against its schema is sent
 *    again on its own with the `extract` prompt of §10.3 (per-question
 *    fallback), so one bad answer never costs the others;
 * 3. a batch call that failed outright (transport error, rejected key)
 *    counts as that failure for every answer in it; the job's attempts and
 *    backoff take it from there, as before.
 *
 * Returns one CallOutcome per response id, shaped like a single `extract`.
 */
final class CropExtractor
{
    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly ExtractionRequests $single,
        private readonly BatchExtractionRequests $batch,
    ) {}

    /**
     * @param  array<int, array{response: Response, question: Question, criteria: list<RubricCriterion>, crop: GeminiImage, final: GeminiImage|null}>  $items  by response id
     * @return array<int, CallOutcome> by response id
     */
    public function extract(array $items, GeminiKey $key, string $subject, string $gradeLabel, ?int $assignmentId): array
    {
        if ($items === []) {
            return [];
        }
        uasort($items, fn (array $a, array $b) => [(int) $a['question']->position, $a['response']->id] <=> [(int) $b['question']->position, $b['response']->id]);
        $max = max(1, (int) config('services.gemini.page_max_questions', 15));

        $calls = [];
        $chunks = [];
        foreach (array_chunk($items, $max, true) as $i => $chunk) {
            $chunks[$i] = $chunk;
            $calls[$i] = count($chunk) === 1
                ? $this->singleCall(reset($chunk), $subject, $gradeLabel, $assignmentId)
                : $this->batch->forItems(array_values($chunk), $subject, $gradeLabel, $assignmentId);
        }
        $first = $this->gateway->run($calls, $key);

        $outcomes = [];
        $fallback = [];
        foreach ($chunks as $i => $chunk) {
            $outcome = $first[$i];
            if (count($chunk) === 1) {
                $outcomes[array_key_first($chunk)] = $outcome;

                continue;
            }
            if (! $outcome->isOk()) {
                foreach (array_keys($chunk) as $responseId) {
                    if ($outcome->status === CallOutcome::INVALID_OUTPUT) {
                        $fallback[$responseId] = $chunk[$responseId];
                    } else {
                        $outcomes[$responseId] = $outcome;
                    }
                }

                continue;
            }
            $answers = (array) ($outcome->data['answers'] ?? []);
            foreach ($chunk as $responseId => $item) {
                $answer = $answers[(int) $item['question']->position] ?? null;
                if (is_array($answer) && is_array($answer['data'] ?? null)) {
                    $outcomes[$responseId] = new CallOutcome(CallOutcome::OK, $answer['data']);
                } else {
                    $fallback[$responseId] = $item;
                }
            }
        }

        if ($fallback !== []) {
            $calls = [];
            foreach ($fallback as $responseId => $item) {
                $calls[$responseId] = $this->singleCall($item, $subject, $gradeLabel, $assignmentId);
            }
            $outcomes = $this->gateway->run($calls, $key) + $outcomes;
        }

        return $outcomes;
    }

    /**
     * @param  array{response: Response, question: Question, criteria: list<RubricCriterion>, crop: GeminiImage, final: GeminiImage|null}  $item
     */
    private function singleCall(array $item, string $subject, string $gradeLabel, ?int $assignmentId): GeminiCall
    {
        return $this->single->forCrops($item['response'], $item['question'], $item['criteria'], $subject, $gradeLabel, $item['crop'], $item['final'], $assignmentId);
    }
}
