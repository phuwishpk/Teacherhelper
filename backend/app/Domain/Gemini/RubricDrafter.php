<?php

namespace App\Domain\Gemini;

/**
 * `rubric_draft` (DESIGN §10.4) through the gateway: prompt file, schema,
 * the server-side rubric checks (points sum to max_points, exactly one core
 * criterion) with one retry of invalid output, and ai_calls logging.
 */
final class RubricDrafter
{
    public const PURPOSE = 'rubric_draft';

    /** ai_calls.feature (DESIGN §21.8). */
    public const FEATURE = 'rubric_ai_draft';

    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /** @throws GeminiException */
    public function draft(RubricDraftRequest $request, GeminiKey $key): RubricDraft
    {
        $prompt = $this->prompts->get(self::PURPOSE, $request->type);
        $call = new GeminiCall(
            request: new GeminiRequest(
                purpose: self::PURPOSE,
                type: $request->type,
                promptVersion: $prompt->versionLabel(),
                systemInstruction: $prompt->renderSystem(),
                userText: $prompt->renderUser([
                    'subject' => $request->subject,
                    'grade_label' => $request->gradeLabel,
                    'max_points' => PromptText::number($request->maxPoints),
                    'prompt_text' => trim($request->promptText),
                    'answer_key_text' => $request->answerKeyText,
                ]),
                responseSchema: ResponseSchemas::get(self::PURPOSE, $request->type),
                temperature: $prompt->temperature,
                hints: ['type' => $request->type, 'max_points' => $request->maxPoints, 'question_text' => $request->promptText],
                thinkingLevel: $prompt->thinking,
                maxOutputTokens: $prompt->maxOutputTokens,
            ),
            questionId: $request->questionId,
            check: function (array $data) use ($request) {
                RubricDraft::fromArray($data)->validateFor($request->type, $request->maxPoints);

                return $data;
            },
            feature: self::FEATURE,
        );

        return RubricDraft::fromArray((array) $this->gateway->runOne($call, $key)->data);
    }
}
