<?php

namespace App\Domain\Gemini;

use App\Domain\Grading\AnswerMatcher;
use App\Domain\Practice\PracticeItemData;
use App\Models\PracticeItem;

/**
 * Output of `practice_gen` (DESIGN §10.6):
 * {items: [{prompt_th, answer_type, options?, accepted_answers, numeric?, explanation_th}]}
 * checked and normalised into practice_items rows (§8.5 shapes). Anything
 * that does not hold is invalid_output, which the gateway retries once.
 */
final readonly class PracticeDraft
{
    /**
     * @param  list<array{answer_type: string, prompt_text: string, options: list<array{key: string, text: string}>|null, answer_key: array<string, mixed>, explanation: string}>  $items
     */
    public function __construct(public array $items) {}

    /**
     * @param  array<string, mixed>  $json
     *
     * @throws GeminiException invalid_output
     */
    public static function fromArray(array $json, int $expected): self
    {
        $raw = $json['items'] ?? null;
        if (! is_array($raw) || $raw === []) {
            throw GeminiException::invalidOutput('items is empty');
        }
        if (count($raw) > PracticeGenRequest::MAX_COUNT) {
            throw GeminiException::invalidOutput('too many items');
        }

        $items = [];
        foreach (array_values($raw) as $i => $item) {
            if (! is_array($item)) {
                throw GeminiException::invalidOutput("item {$i} is not an object");
            }
            $items[] = self::item($item, $i);
        }

        return new self(array_slice($items, 0, max(1, $expected)));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{answer_type: string, prompt_text: string, options: list<array{key: string, text: string}>|null, answer_key: array<string, mixed>, explanation: string}
     */
    private static function item(array $item, int $i): array
    {
        $type = $item['answer_type'] ?? null;
        $prompt = trim((string) ($item['prompt_th'] ?? ''));
        $explanation = trim((string) ($item['explanation_th'] ?? ''));
        $accepted = array_values(array_filter(
            array_map(fn ($a) => is_scalar($a) ? trim((string) $a) : '', (array) ($item['accepted_answers'] ?? [])),
            fn (string $a) => $a !== '',
        ));

        if (! in_array($type, PracticeItem::TYPES, true)) {
            throw GeminiException::invalidOutput("item {$i}: unknown answer_type");
        }
        if ($prompt === '' || mb_strlen($prompt, 'UTF-8') > PracticeItemData::MAX_PROMPT) {
            throw GeminiException::invalidOutput("item {$i}: prompt_th is empty or too long");
        }
        if ($explanation === '' || mb_strlen($explanation, 'UTF-8') > PracticeItemData::MAX_EXPLANATION) {
            throw GeminiException::invalidOutput("item {$i}: explanation_th is empty or too long");
        }
        if ($accepted === [] || count($accepted) > PracticeItemData::MAX_ACCEPTED) {
            throw GeminiException::invalidOutput("item {$i}: accepted_answers must hold 1–".PracticeItemData::MAX_ACCEPTED.' answers');
        }

        if ($type === PracticeItem::TYPE_MCQ) {
            $texts = array_values(array_filter(
                array_map(fn ($o) => is_scalar($o) ? trim((string) $o) : '', (array) ($item['options'] ?? [])),
                fn (string $o) => $o !== '',
            ));
            $count = count($texts);
            if ($count < PracticeItemData::MIN_OPTIONS || $count > PracticeItemData::MAX_OPTIONS || count(array_unique($texts)) !== $count) {
                throw GeminiException::invalidOutput("item {$i}: mcq needs 2–6 distinct options");
            }
            $correct = null;
            foreach ($texts as $index => $text) {
                if (AnswerMatcher::normalize($text) === AnswerMatcher::normalize($accepted[0])) {
                    $correct = PracticeItemData::letter($index);
                    break;
                }
            }
            if ($correct === null) {
                throw GeminiException::invalidOutput("item {$i}: the correct answer is not one of the options");
            }

            return [
                'answer_type' => $type,
                'prompt_text' => $prompt,
                'options' => array_map(fn (string $text, int $index) => ['key' => PracticeItemData::letter($index), 'text' => $text], $texts, array_keys($texts)),
                'answer_key' => ['correct' => $correct],
                'explanation' => $explanation,
            ];
        }

        $key = ['accepted' => $accepted];
        $numeric = $item['numeric'] ?? null;
        if (is_array($numeric) && is_numeric($numeric['value'] ?? null)) {
            $absTol = $numeric['abs_tol'] ?? 0;
            if (! is_numeric($absTol) || (float) $absTol < 0) {
                throw GeminiException::invalidOutput("item {$i}: abs_tol must be >= 0");
            }
            $key['numeric'] = ['value' => (float) $numeric['value'], 'abs_tol' => (float) $absTol];
        } elseif ($type === PracticeItem::TYPE_NUMERIC) {
            $parsed = AnswerMatcher::parseNumber($accepted[0]);
            if ($parsed === null) {
                throw GeminiException::invalidOutput("item {$i}: numeric item without a numeric value");
            }
            $key['numeric'] = ['value' => $parsed, 'abs_tol' => 0.0];
        }

        return [
            'answer_type' => $type,
            'prompt_text' => $prompt,
            'options' => null,
            'answer_key' => $key,
            'explanation' => $explanation,
        ];
    }
}
