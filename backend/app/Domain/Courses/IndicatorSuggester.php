<?php

namespace App\Domain\Courses;

use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\GeminiCall;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\PromptRepository;
use App\Domain\Gemini\ResponseSchemas;
use App\Domain\Gemini\RubricDraftRequest;
use App\Models\Assignment;
use App\Models\LessonPlan;
use App\Models\Question;
use App\Models\Skill;

/**
 * `indicator_suggest` (DESIGN §10.1, §20.3): Gemini picks, for each
 * question of an assignment, indicators from the linked lesson plan's
 * indicators only, with a short reason. Text only (no images), thinking
 * low, 1,024 output tokens (§21.6), so the questions go in calls of
 * QUESTIONS_PER_CALL sent in parallel.
 *
 * Codes are matched to the plan's indicators exactly, then after
 * IndicatorMatcher::normalize(); a code outside the plan is dropped (never
 * an invalid answer: the rest of the reply is still good). At most
 * MAX_PER_QUESTION indicators per question.
 *
 * All or nothing: when any call fails the whole suggestion fails
 * (GeminiException), so the job's retry asks again for every question.
 */
final class IndicatorSuggester
{
    public const PURPOSE = 'indicator_suggest';

    public const TYPE = 'general';

    /** ai_calls.feature (DESIGN §21.8). */
    public const FEATURE = 'indicator_suggest';

    public const MAX_PER_QUESTION = 3;

    /** Keeps each reply well under the 1,024-token output cap (about 60 tokens per question). */
    public const QUESTIONS_PER_CALL = 10;

    /** Question text sent per question: enough to recognise what it assesses. */
    private const MAX_QUESTION_CHARS = 600;

    private const MAX_OBJECTIVES_CHARS = 1500;

    private const MAX_REASON_CHARS = 255;

    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * suggestions are keyed by question id (a question without a fitting
     * indicator is absent); dropped counts the codes that were not
     * indicators of the plan.
     *
     * @param  list<Question>  $questions  in position order
     * @param  list<Skill>  $indicators  the lesson plan's indicators
     * @return array{suggestions: array<int, list<array{skill_id: int, reason_th: string|null}>>, dropped: int}
     *
     * @throws GeminiException
     */
    public function suggest(Assignment $assignment, LessonPlan $plan, array $questions, array $indicators, GeminiKey $key): array
    {
        if ($questions === [] || $indicators === []) {
            return ['suggestions' => [], 'dropped' => 0];
        }

        $calls = [];
        foreach (array_chunk($questions, self::QUESTIONS_PER_CALL) as $i => $chunk) {
            $calls[$i] = $this->call($assignment, $plan, $chunk, $indicators);
        }
        $outcomes = $this->gateway->run($calls, $key);

        foreach ($outcomes as $outcome) {
            if (! $outcome->isOk()) {
                throw new GeminiException((string) $outcome->error, match ($outcome->status) {
                    CallOutcome::INVALID_OUTPUT => GeminiException::INVALID_OUTPUT,
                    CallOutcome::KEY_INVALID => GeminiException::KEY_INVALID,
                    default => GeminiException::ERROR,
                });
            }
        }

        $byPosition = [];
        foreach ($questions as $question) {
            $byPosition[(int) $question->position] = $question;
        }
        $exact = [];
        $normalised = [];
        foreach ($indicators as $skill) {
            $exact[$skill->code] ??= $skill;
            $normalised[IndicatorMatcher::normalize($skill->code)] ??= $skill;
        }

        $suggestions = [];
        $dropped = 0;
        foreach ($outcomes as $outcome) {
            foreach ((array) ($outcome->data['questions'] ?? []) as $answer) {
                $question = $byPosition[(int) ($answer['question_no'] ?? 0)] ?? null;
                if ($question === null || isset($suggestions[$question->id])) {
                    continue;
                }
                $reason = self::reason($answer['reason_th'] ?? null);
                $picked = [];
                foreach ((array) ($answer['indicator_codes'] ?? []) as $code) {
                    $code = trim((string) $code);
                    $skill = $exact[$code] ?? $normalised[IndicatorMatcher::normalize($code)] ?? null;
                    if ($skill === null) {
                        $dropped++; // not an indicator of the plan (DESIGN §20.10)

                        continue;
                    }
                    if (count($picked) < self::MAX_PER_QUESTION) {
                        $picked[$skill->id] = ['skill_id' => $skill->id, 'reason_th' => $reason];
                    }
                }
                if ($picked !== []) {
                    $suggestions[$question->id] = array_values($picked);
                }
            }
        }

        return ['suggestions' => $suggestions, 'dropped' => $dropped];
    }

    /**
     * @param  list<Question>  $questions
     * @param  list<Skill>  $indicators
     */
    private function call(Assignment $assignment, LessonPlan $plan, array $questions, array $indicators): GeminiCall
    {
        $prompt = $this->prompts->get(self::PURPOSE, self::TYPE);
        $briefs = array_map(fn (Question $q) => [
            'question_no' => (int) $q->position,
            'type' => $q->type,
            'text' => self::cut(trim($q->prompt_text), self::MAX_QUESTION_CHARS),
        ], $questions);
        $codes = array_map(fn (Skill $s) => $s->code, $indicators);
        $objectives = trim((string) $plan->objectives);

        return new GeminiCall(
            request: new GeminiRequest(
                purpose: self::PURPOSE,
                type: self::TYPE,
                promptVersion: $prompt->versionLabel(),
                systemInstruction: $prompt->renderSystem(),
                userText: $prompt->renderUser([
                    'subject' => (string) ($assignment->subject?->name ?? $plan->course?->subject?->name ?? '-'),
                    'grade_label' => RubricDraftRequest::gradeLabel((int) ($plan->course?->grade_level ?? $assignment->classroom?->grade_level ?? 1)),
                    'plan_title' => trim($plan->title),
                    'plan_objectives' => $objectives === '' ? '-' : self::cut($objectives, self::MAX_OBJECTIVES_CHARS),
                    'indicators_list' => implode("\n", array_map(fn (Skill $s) => '- '.$s->code.': '.trim($s->name), $indicators)),
                    'questions_json' => (string) json_encode($briefs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]),
                responseSchema: ResponseSchemas::get(self::PURPOSE, self::TYPE),
                temperature: $prompt->temperature,
                hints: [
                    // FakeGeminiClient reads its markers from question_text.
                    'question_text' => implode("\n", array_column($briefs, 'text')),
                    'questions' => array_column($briefs, 'text', 'question_no'),
                    'indicator_codes' => $codes,
                ],
                thinkingLevel: $prompt->thinking,
                maxOutputTokens: $prompt->maxOutputTokens,
            ),
            feature: self::FEATURE,
            assignmentId: $assignment->id,
            questionCount: count($questions),
        );
    }

    private static function reason(mixed $reason): ?string
    {
        $reason = trim((string) (is_string($reason) ? $reason : ''));

        return $reason === '' ? null : self::cut($reason, self::MAX_REASON_CHARS);
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
