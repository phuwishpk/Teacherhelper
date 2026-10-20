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
 * the teacher's data (AssistantContext), then the last HISTORY_TURNS turns
 * and the new message. An earlier answer with a plan card carries a short
 * summary of the card, so a change request can build on it.
 */
final class AssistantRequests
{
    public const PURPOSE = 'assistant_chat';

    public const TYPE = 'general';

    /** Earlier messages sent with a new one. */
    public const HISTORY_TURNS = 20;

    /** Seconds: a card of 20 questions is a long answer (HOSTING max_execution_time ≥ 120). */
    public const TIMEOUT = 90;

    public function __construct(
        private readonly PromptRepository $prompts,
        private readonly AssistantKnowledge $knowledge,
    ) {}

    /**
     * @param  list<AssistantMessage>  $history  earlier messages, oldest first (errors are skipped)
     * @param  string  $context  AssistantContext::build()
     */
    public function call(string $message, array $history, int $conversationId, string $context = ''): GeminiCall
    {
        $prompt = $this->prompts->get(self::PURPOSE, self::TYPE);
        $turns = [];
        foreach ($history as $m) {
            if ($m->role === AssistantMessage::ROLE_USER || $m->role === AssistantMessage::ROLE_ASSISTANT) {
                $turns[] = ['role' => $m->role === AssistantMessage::ROLE_USER ? 'user' : 'model', 'text' => $m->content.self::cardSummary($m)];
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
                    ."\n\n# KNOWLEDGE: manual sections for this question\n\n".$manual
                    .($context === '' ? '' : "\n\n".$context),
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
     * @return array{answer: string, plan: array<string, mixed>|null, apply_plan: bool}
     *
     * @throws GeminiException invalid_output
     */
    public static function check(array $data): array
    {
        $answer = trim((string) ($data['answer'] ?? ''));
        if ($answer === '') {
            throw GeminiException::invalidOutput('the answer is empty');
        }

        return [
            'answer' => $answer,
            'plan' => is_array($data['plan'] ?? null) ? $data['plan'] : null,
            'apply_plan' => ($data['apply_plan'] ?? false) === true,
        ];
    }

    /** "[การ์ด …]" after an answer that had a card: its state, target and questions in short. */
    private static function cardSummary(AssistantMessage $m): string
    {
        $plan = $m->plan;
        if (! is_array($plan)) {
            return '';
        }
        $target = $plan['target'] ?? [];
        $lines = ["\n[CARD {$m->plan_status}: ".(isset($target['assignment_id']) ? "assignment {$target['assignment_id']}" : 'new assignment')
            .' "'.($target['title'] ?? '').'" ('.($target['kind'] ?? '?').')'];
        foreach ($plan['questions'] ?? [] as $i => $q) {
            $lines[] = ($i + 1).". {$q['type']}: ".mb_substr((string) $q['prompt_text'], 0, 60);
        }
        if (isset($plan['result'])) {
            $lines[] = "created {$plan['result']['created']} questions";
        }

        return implode("\n", $lines).']';
    }
}
