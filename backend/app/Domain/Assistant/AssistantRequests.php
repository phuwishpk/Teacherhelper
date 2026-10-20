<?php

namespace App\Domain\Assistant;

use App\Domain\Gemini\GeminiCall;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\PromptRepository;
use App\Domain\Gemini\ResponseSchemas;
use App\Models\AssistantMessage;

/**
 * The Gemini call of one assistant message (DESIGN §37): the prompt's rules,
 * then the core knowledge (the same for every message, so Gemini's implicit
 * cache can reuse it), then the manual sections that fit the question, then
 * the last HISTORY_TURNS turns and the new message.
 */
final class AssistantRequests
{
    public const PURPOSE = 'assistant_chat';

    public const TYPE = 'general';

    /** Earlier messages sent with a new one. */
    public const HISTORY_TURNS = 20;

    /** Seconds: a long system instruction takes longer than a crop. */
    public const TIMEOUT = 60;

    public function __construct(
        private readonly PromptRepository $prompts,
        private readonly AssistantKnowledge $knowledge,
    ) {}

    /**
     * @param  list<AssistantMessage>  $history  earlier messages, oldest first (errors are skipped)
     */
    public function call(string $message, array $history, int $conversationId): GeminiCall
    {
        $prompt = $this->prompts->get(self::PURPOSE, self::TYPE);
        $turns = [];
        foreach ($history as $m) {
            if ($m->role === AssistantMessage::ROLE_USER || $m->role === AssistantMessage::ROLE_ASSISTANT) {
                $turns[] = ['role' => $m->role === AssistantMessage::ROLE_USER ? 'user' : 'model', 'text' => $m->content];
            }
        }
        $turns = array_slice($turns, -self::HISTORY_TURNS);
        // The question and the teacher's previous one pick the manual sections.
        $lastUser = '';
        foreach (array_reverse($turns) as $t) {
            if ($t['role'] === 'user') {
                $lastUser = $t['text'];
                break;
            }
        }
        $sections = $this->knowledge->sectionsFor($message.' '.$lastUser);
        $manual = $sections === [] ? '(none matched)' : implode("\n\n", array_map(
            fn (array $s) => "### {$s['title']}\n{$s['text']}",
            $sections,
        ));

        return new GeminiCall(
            request: new GeminiRequest(
                purpose: self::PURPOSE,
                type: self::TYPE,
                promptVersion: $prompt->versionLabel(),
                systemInstruction: $prompt->renderSystem()
                    ."\n\n# KNOWLEDGE: core\n\n".$this->knowledge->core()
                    ."\n\n# KNOWLEDGE: manual sections for this question\n\n".$manual,
                userText: $prompt->renderUser(['message' => $message]),
                responseSchema: ResponseSchemas::get(self::PURPOSE, self::TYPE),
                temperature: $prompt->temperature,
                hints: ['question_text' => $message, 'history_turns' => count($turns), 'sections' => count($sections)],
                timeout: self::TIMEOUT,
                thinkingLevel: $prompt->thinking,
                maxOutputTokens: $prompt->maxOutputTokens,
                history: $turns,
            ),
            check: fn (array $data) => self::check($data),
            feature: 'assistant_conversation:'.$conversationId,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{answer: string}
     *
     * @throws GeminiException invalid_output
     */
    public static function check(array $data): array
    {
        $answer = trim((string) ($data['answer'] ?? ''));
        if ($answer === '') {
            throw GeminiException::invalidOutput('the answer is empty');
        }

        return ['answer' => $answer];
    }
}
