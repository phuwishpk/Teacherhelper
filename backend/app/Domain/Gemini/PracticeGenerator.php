<?php

namespace App\Domain\Gemini;

/**
 * `practice_gen` (DESIGN §10.6) through the gateway: prompt file, schema,
 * the server-side item checks (PracticeDraft) with one retry of invalid
 * output, and ai_calls logging (skill_id).
 */
final class PracticeGenerator
{
    public const PURPOSE = 'practice_gen';

    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /** @throws GeminiException */
    public function generate(PracticeGenRequest $request, GeminiKey $key): PracticeDraft
    {
        $prompt = $this->prompts->get(self::PURPOSE, 'general');
        $examples = $request->examples === []
            ? '(no examples yet; write questions typical for this skill)'
            : PromptText::numberedLines($request->examples);

        $call = new GeminiCall(
            request: new GeminiRequest(
                purpose: self::PURPOSE,
                type: 'general',
                promptVersion: $prompt->versionLabel(),
                systemInstruction: $prompt->renderSystem(),
                userText: $prompt->renderUser([
                    'skill_code' => $request->skillCode,
                    'skill_name' => trim($request->skillName),
                    'subject' => $request->subject,
                    'grade_label' => $request->gradeLabel,
                    'examples' => $examples,
                    'n' => $request->count,
                ]),
                responseSchema: ResponseSchemas::get(self::PURPOSE, 'general'),
                temperature: $prompt->temperature,
                hints: [
                    'type' => 'general',
                    'question_text' => $request->skillName,
                    'skill_code' => $request->skillCode,
                    'n' => $request->count,
                ],
            ),
            skillId: $request->skillId,
            check: function (array $data) use ($request) {
                PracticeDraft::fromArray($data, $request->count);

                return $data;
            },
        );

        return PracticeDraft::fromArray((array) $this->gateway->runOne($call, $key)->data, $request->count);
    }
}
