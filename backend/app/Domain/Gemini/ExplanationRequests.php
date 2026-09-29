<?php

namespace App\Domain\Gemini;

use App\Models\Question;
use App\Models\Response;
use App\Models\RubricCriterion;

/**
 * Builds the `explanation` request (DESIGN §10.5) for an answer that did not
 * get full marks. Text only: the transcription Gemini already made, the error
 * types and the key. The image is never sent twice. feature labels the
 * ai_calls row with the path that asked (grading_crop, grading_page,
 * review_regenerate; DESIGN §21.8).
 */
final class ExplanationRequests
{
    public const PURPOSE = 'explanation';

    public const MAX_EXPLANATION = 1200;

    public const MAX_NEXT_STEP = 600;

    private const ERROR_TYPE_LABELS = [
        'concept' => 'misunderstood the concept',
        'procedure' => 'used a wrong method or procedure',
        'calculation' => 'calculation slip',
        'careless' => 'careless or copying mistake',
        'incomplete' => 'incomplete answer',
        'misread_question' => 'misread the question',
        'spelling_grammar' => 'spelling or grammar',
        'no_answer' => 'no answer',
        'other' => 'other',
    ];

    public function __construct(private readonly PromptRepository $prompts) {}

    /**
     * @param  array<string, mixed>  $extraction  validated output of `extract`
     * @param  list<RubricCriterion>  $criteria
     */
    public function forResponse(Response $response, Question $question, array $criteria, string $gradeLabel, array $extraction, ?string $feature = null, ?int $assignmentId = null): GeminiCall
    {
        $prompt = $this->prompts->get(self::PURPOSE, 'general');
        $vars = [
            'grade_label' => $gradeLabel,
            'prompt_text' => trim($question->prompt_text),
            'key_or_reference' => self::keyText($question, $criteria),
            'transcription_text' => self::transcription($question->type, $extraction),
            'error_types' => implode(', ', array_map(fn (string $t) => self::ERROR_TYPE_LABELS[$t] ?? $t, (array) ($extraction['error_types'] ?? []))) ?: 'none listed',
            'teacher_notes' => self::notes($extraction),
            'first_invalid_line' => self::firstInvalidLine($question->type, $extraction),
        ];

        $request = new GeminiRequest(
            purpose: self::PURPOSE,
            type: 'general',
            promptVersion: $prompt->versionLabel(),
            systemInstruction: $prompt->renderSystem(['grade_label' => $gradeLabel]),
            userText: $prompt->renderUser($vars),
            responseSchema: ResponseSchemas::get(self::PURPOSE, 'general'),
            temperature: $prompt->temperature,
            hints: ['type' => $question->type, 'question_text' => $question->prompt_text, 'error_types' => $extraction['error_types'] ?? []],
            thinkingLevel: $prompt->thinking,
            maxOutputTokens: $prompt->maxOutputTokens,
        );

        return new GeminiCall(
            request: $request,
            responseId: $response->id,
            questionId: $question->id,
            check: fn (array $data) => self::normalize($data),
            feature: $feature,
            assignmentId: $assignmentId,
        );
    }

    /** The text stored in responses.explanation: the explanation, a blank line, the next step. */
    public static function text(array $data): string
    {
        return trim($data['explanation_th'])."\n\n".trim($data['next_step_th']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalize(array $data): array
    {
        $explanation = trim((string) $data['explanation_th']);
        $next = trim((string) $data['next_step_th']);
        if ($explanation === '' || mb_strlen($explanation, 'UTF-8') > self::MAX_EXPLANATION) {
            throw GeminiException::invalidOutput('explanation_th is empty or too long');
        }
        if (mb_strlen($next, 'UTF-8') > self::MAX_NEXT_STEP) {
            throw GeminiException::invalidOutput('next_step_th is too long');
        }

        return ['explanation_th' => $explanation, 'next_step_th' => $next];
    }

    /**
     * @param  list<RubricCriterion>  $criteria
     */
    private static function keyText(Question $question, array $criteria): string
    {
        $key = $question->answer_key ?? [];

        return match ($question->type) {
            Question::TYPE_SHORT => PromptText::quotedList(array_map('strval', (array) ($key['accepted'] ?? []))),
            Question::TYPE_SHOW_WORK => 'final answer '.PromptText::quotedList(array_map('strval', (array) ($key['final']['accepted'] ?? [])))
                ."\nreference steps:\n".PromptText::numberedLines(array_map('strval', (array) ($key['reference_steps'] ?? []))),
            default => "rubric:\n".PromptText::numberedLines(array_map(
                fn (RubricCriterion $c) => trim($c->description).($c->is_core ? ' [core idea]' : ''),
                $criteria,
            )),
        };
    }

    /**
     * @param  array<string, mixed>  $extraction
     */
    private static function transcription(string $type, array $extraction): string
    {
        $text = match ($type) {
            Question::TYPE_SHORT => (string) ($extraction['answer_text'] ?? ''),
            Question::TYPE_SHOW_WORK => implode("\n", array_map(
                fn (array $s) => 'line '.$s['line'].': '.$s['text'],
                (array) ($extraction['steps'] ?? []),
            ))."\nfinal answer: ".($extraction['final_answer_text'] ?? ''),
            default => (string) ($extraction['transcription'] ?? ''),
        };

        return trim($text) === '' ? '(nothing written)' : trim($text);
    }

    /**
     * @param  array<string, mixed>  $extraction
     */
    private static function notes(array $extraction): string
    {
        $notes = [];
        if (is_string($extraction['summary_th'] ?? null) && trim($extraction['summary_th']) !== '') {
            $notes[] = trim($extraction['summary_th']);
        }
        foreach ((array) ($extraction['steps'] ?? []) as $step) {
            if (! ($step['valid'] ?? true) && is_string($step['note_th'] ?? null) && trim($step['note_th']) !== '') {
                $notes[] = 'line '.$step['line'].': '.trim($step['note_th']);
            }
        }
        foreach ((array) ($extraction['criteria'] ?? []) as $criterion) {
            if (($criterion['level'] ?? 'met') !== 'met' && is_string($criterion['evidence_th'] ?? null) && trim($criterion['evidence_th']) !== '') {
                $notes[] = 'criterion '.$criterion['criterion_id'].': '.trim($criterion['evidence_th']);
            }
        }

        return $notes === [] ? '-' : implode(' / ', $notes);
    }

    /**
     * @param  array<string, mixed>  $extraction
     */
    private static function firstInvalidLine(string $type, array $extraction): string
    {
        if ($type !== Question::TYPE_SHOW_WORK) {
            return 'not applicable';
        }
        foreach ((array) ($extraction['steps'] ?? []) as $step) {
            if (! $step['valid']) {
                return 'line '.$step['line'].': '.$step['text'];
            }
        }

        return 'none';
    }
}
