<?php

namespace App\Domain\Gemini;

use App\Models\Question;

/**
 * Checks an `extract` output beyond its JSON Schema (DESIGN §10.3) and
 * returns it normalised for responses.extraction:
 *
 * - text fields have sane lengths;
 * - open: exactly one entry per rubric criterion_id 1..n (a missing or
 *   unknown criterion would silently change the score);
 * - error_types without duplicates; a blank answer is tagged no_answer.
 *
 * Anything wrong throws GeminiException::invalidOutput().
 */
final class ExtractionValidator
{
    public const MAX_TEXT = 4000;

    public const MAX_NOTE = 1000;

    /**
     * @param  array<string, mixed>  $data  already valid against schemas/extract.{type}.json
     * @return array<string, mixed>
     */
    public static function normalize(string $type, array $data, int $criteriaCount = 0): array
    {
        foreach (['summary_th' => self::MAX_NOTE, 'answer_text' => self::MAX_TEXT, 'transcription' => self::MAX_TEXT, 'final_answer_text' => self::MAX_NOTE] as $field => $max) {
            if (isset($data[$field]) && mb_strlen((string) $data[$field], 'UTF-8') > $max) {
                throw GeminiException::invalidOutput("{$field} is longer than {$max} characters");
            }
        }

        if ($type === Question::TYPE_SHOW_WORK) {
            foreach ($data['steps'] as $i => $step) {
                if (mb_strlen($step['text'], 'UTF-8') > self::MAX_NOTE || mb_strlen((string) ($step['note_th'] ?? ''), 'UTF-8') > self::MAX_NOTE) {
                    throw GeminiException::invalidOutput("steps[{$i}] is too long");
                }
            }
        }

        if ($type === Question::TYPE_OPEN) {
            $ids = array_map(fn (array $c) => (int) $c['criterion_id'], $data['criteria']);
            sort($ids);
            if ($criteriaCount < 1 || $ids !== range(1, $criteriaCount)) {
                throw GeminiException::invalidOutput("criteria must list criterion_id 1..{$criteriaCount} exactly once, got [".implode(',', $ids).']');
            }
            foreach ($data['criteria'] as $i => $c) {
                if (mb_strlen((string) ($c['evidence_th'] ?? ''), 'UTF-8') > self::MAX_NOTE) {
                    throw GeminiException::invalidOutput("criteria[{$i}].evidence_th is too long");
                }
            }
            usort($data['criteria'], fn (array $a, array $b) => $a['criterion_id'] <=> $b['criterion_id']);
        }

        $errors = array_values(array_unique($data['error_types']));
        if ($data['blank'] && $errors === []) {
            $errors = ['no_answer'];
        }
        $data['error_types'] = $errors;

        return $data;
    }
}
