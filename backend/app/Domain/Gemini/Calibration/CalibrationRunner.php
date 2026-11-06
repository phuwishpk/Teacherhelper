<?php

namespace App\Domain\Gemini\Calibration;

use App\Domain\AnswerKeys\AnswerKeyReader;
use App\Domain\AnswerKeys\AnswerKeyResult;
use App\Domain\Gemini\BatchExtractionRequests;
use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\ExtractionRequests;
use App\Domain\Gemini\ExtractionValidator;
use App\Domain\Gemini\GeminiCall;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiImage;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\PageExtractionRequests;
use App\Domain\Gemini\RubricDraftRequest;
use App\Models\Response;

/**
 * Sends the golden fixtures of one kind at one media resolution through the
 * production request builders (DESIGN §21.10), so the numbers are those of
 * the real prompts:
 *
 *   short, work  crops in extract_batch calls of up to page_max_questions
 *                (a lone crop: extract), as the crop path sends them (§21.4)
 *   page         one extract_page call per page (§19.4)
 *   document     one answer_key_read call per document (§19.5)
 *
 * Every image part goes at the level under test. No per-question fallback:
 * an answer the model leaves out or gets wrong counts as missing, so a
 * level that loses answers shows it. Calls go through GeminiGateway (schema
 * check, one retry of invalid output) and are logged to ai_calls with
 * feature `calibration`.
 */
final class CalibrationRunner
{
    public const FEATURE = 'calibration';

    public function __construct(
        private readonly ExtractionRequests $single,
        private readonly BatchExtractionRequests $batch,
        private readonly PageExtractionRequests $pages,
        private readonly AnswerKeyReader $keys,
    ) {}

    /** Calls one (kind, level) run makes, before the gateway's retries. */
    public function callCount(CalibrationManifest $manifest, string $kind): int
    {
        $units = count($manifest->units($kind));
        if (in_array($kind, ['short', 'work'], true)) {
            return (int) ceil($units / self::chunkSize());
        }

        return $units;
    }

    /**
     * results: sample id => the extraction read (null = missing).
     *
     * @return array{results: array<string, array<string, mixed>|null>, calls: int, errors: list<string>}
     */
    public function run(GeminiGateway $gateway, GeminiKey $key, CalibrationManifest $manifest, string $kind, string $level): array
    {
        [$calls, $readers] = $this->plan($manifest, $kind, $level);
        $outcomes = $calls === [] ? [] : $gateway->run($calls, $key);

        $results = [];
        $errors = [];
        foreach ($readers as $callId => $reader) {
            $outcome = $outcomes[$callId] ?? new CallOutcome(CallOutcome::ERROR, null, 'no outcome');
            if (! $outcome->isOk()) {
                $errors[] = "{$callId}: {$outcome->status} ".(string) $outcome->error;
            }
            $results += $reader($outcome);
        }

        return ['results' => $results, 'calls' => count($calls), 'errors' => $errors];
    }

    /**
     * @return array{0: array<string, GeminiCall>, 1: array<string, callable(CallOutcome): array<string, array<string, mixed>|null>>}
     */
    private function plan(CalibrationManifest $manifest, string $kind, string $level): array
    {
        $subject = $manifest->subject;
        $grade = RubricDraftRequest::gradeLabel($manifest->gradeLevel);
        $calls = [];
        $readers = [];

        if (in_array($kind, ['short', 'work'], true)) {
            foreach (array_chunk($manifest->units($kind), self::chunkSize()) as $c => $chunk) {
                $items = [];
                $ids = [];
                foreach ($chunk as $n => $unit) {
                    $sample = $unit->samples[0];
                    $question = clone $sample->question;
                    $question->position = $n + 1;
                    $items[] = [
                        'response' => new Response,
                        'question' => $question,
                        'criteria' => $sample->criteria,
                        'crop' => new GeminiImage($unit->files[0]['bytes'], $unit->files[0]['mime_type'], $level),
                        'final' => isset($unit->files[1]) ? new GeminiImage($unit->files[1]['bytes'], $unit->files[1]['mime_type'], $level) : null,
                    ];
                    $ids[$n + 1] = $sample->id;
                }
                $callId = "{$kind}-{$c}";
                if (count($items) === 1) {
                    $item = $items[0];
                    $type = $item['question']->type;
                    $count = count($item['criteria']);
                    $calls[$callId] = new GeminiCall(
                        request: $this->single->request($item['question'], $item['criteria'], $subject, $grade, $item['crop'], $item['final']),
                        check: fn (array $data) => ExtractionValidator::normalize($type, $data, $count),
                        feature: self::FEATURE,
                        questionCount: 1,
                    );
                    $readers[$callId] = fn (CallOutcome $o) => [$ids[1] => $o->isOk() ? (array) $o->data : null];
                } else {
                    $calls[$callId] = self::relabel($this->batch->forItems($items, $subject, $grade));
                    $readers[$callId] = fn (CallOutcome $o) => self::readMulti($o, $ids);
                }
            }

            return [$calls, $readers];
        }

        foreach ($manifest->units($kind) as $unit) {
            $ids = [];
            foreach ($unit->samples as $sample) {
                $ids[(int) $sample->question->position] = $sample->id;
            }
            $file = $unit->files[0];
            if ($kind === 'page') {
                $items = array_map(fn (CalibrationSample $s) => ['question' => $s->question, 'criteria' => $s->criteria], $unit->samples);
                $call = $this->pages->forFile($file['bytes'], $file['mime_type'], 1, $items, $subject, $grade);
                $calls[$unit->id] = self::relabel($call, $level);
                $readers[$unit->id] = fn (CallOutcome $o) => self::readMulti($o, $ids);
            } else {
                $files = array_map(fn (array $f) => ['bytes' => $f['bytes'], 'mime_type' => $f['mime_type'], 'page_count' => 1], $unit->files);
                $questions = array_map(fn (CalibrationSample $s) => $s->question, $unit->samples);
                $request = $this->keys->request(AnswerKeyResult::KIND_READ, $files, $questions, $subject, $grade);
                $calls[$unit->id] = new GeminiCall(
                    request: self::withLevel($request, $level),
                    check: fn (array $data) => AnswerKeyResult::fromGemini(AnswerKeyResult::KIND_READ, $data)->toArray(),
                    feature: self::FEATURE,
                    questionCount: count($questions),
                );
                $readers[$unit->id] = fn (CallOutcome $o) => self::readKey($o, $ids);
            }
        }

        return [$calls, $readers];
    }

    private static function chunkSize(): int
    {
        return max(1, (int) config('services.gemini.page_max_questions', 15));
    }

    /** The production call, labelled `calibration` and (optionally) at another level. */
    private static function relabel(GeminiCall $call, ?string $level = null): GeminiCall
    {
        return new GeminiCall(
            request: $level === null ? $call->request : self::withLevel($call->request, $level),
            check: $call->check,
            feature: self::FEATURE,
            questionCount: $call->questionCount,
            validationSchema: $call->validationSchema,
        );
    }

    private static function withLevel(GeminiRequest $r, string $level): GeminiRequest
    {
        return new GeminiRequest(
            purpose: $r->purpose,
            type: $r->type,
            promptVersion: $r->promptVersion,
            systemInstruction: $r->systemInstruction,
            userText: $r->userText,
            images: array_map(fn (GeminiImage $i) => new GeminiImage($i->data, $i->mimeType, $level, $i->label), $r->images),
            responseSchema: $r->responseSchema,
            temperature: $r->temperature,
            hints: $r->hints,
            timeout: $r->timeout,
            thinkingLevel: $r->thinkingLevel,
            maxOutputTokens: $r->maxOutputTokens,
        );
    }

    /**
     * @param  array<int, string>  $ids  question number => sample id
     * @return array<string, array<string, mixed>|null>
     */
    private static function readMulti(CallOutcome $outcome, array $ids): array
    {
        $answers = $outcome->isOk() ? (array) ($outcome->data['answers'] ?? []) : [];
        $out = [];
        foreach ($ids as $no => $id) {
            $answer = $answers[$no] ?? null;
            $out[$id] = is_array($answer) && ($answer['found'] ?? true) && is_array($answer['data'] ?? null) ? $answer['data'] : null;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, array<string, mixed>|null>
     */
    private static function readKey(CallOutcome $outcome, array $ids): array
    {
        $read = [];
        foreach ($outcome->isOk() ? (array) ($outcome->data['questions'] ?? []) : [] as $q) {
            $read[(int) ($q['question_no'] ?? 0)] = (array) $q;
        }
        $out = [];
        foreach ($ids as $no => $id) {
            $out[$id] = $read[$no] ?? null;
        }

        return $out;
    }
}
