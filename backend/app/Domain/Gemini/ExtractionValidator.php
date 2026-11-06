<?php

namespace App\Domain\Gemini;

use App\Models\Question;

/**
 * Checks an `extract` output beyond its JSON Schema (DESIGN §10.3) and
 * returns it normalised for responses.extraction:
 *
 * - text fields have sane lengths;
 * - open: exactly one entry per rubric criterion_id 1..n (a missing or
 *   unknown criterion would silently change the score). A blank answer
 *   scores 0 without reading the criteria (§11.1), so there a short or empty
 *   list is accepted and every criterion is stored as not_met: a model that
 *   answers blank = true with criteria [] is right, not invalid;
 * - mcq (read from a whole page, extract_page): selected_options without
 *   duplicates;
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
            foreach ($data['criteria'] as $i => $c) {
                if (mb_strlen((string) ($c['evidence_th'] ?? ''), 'UTF-8') > self::MAX_NOTE) {
                    throw GeminiException::invalidOutput("criteria[{$i}].evidence_th is too long");
                }
            }
            $data['criteria'] = $data['blank']
                ? self::blankCriteria($data['criteria'], $criteriaCount)
                : self::everyCriterion($data['criteria'], $criteriaCount);
        }

        if ($type === Question::TYPE_MCQ) {
            // Whole-page only (extract_page): the letters the student marked.
            $data['selected_options'] = array_values(array_unique(array_map('strval', $data['selected_options'])));
        }

        $errors = array_values(array_unique($data['error_types']));
        if ($data['blank'] && $errors === []) {
            $errors = ['no_answer'];
        }
        $data['error_types'] = $errors;

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $criteria
     * @return list<array<string, mixed>> sorted by criterion_id
     */
    private static function everyCriterion(array $criteria, int $count): array
    {
        $ids = array_map(fn (array $c) => (int) $c['criterion_id'], $criteria);
        sort($ids);
        if ($count < 1 || $ids !== range(1, $count)) {
            throw GeminiException::invalidOutput("criteria must list criterion_id 1..{$count} exactly once, got [".implode(',', $ids).']');
        }
        usort($criteria, fn (array $a, array $b) => $a['criterion_id'] <=> $b['criterion_id']);

        return $criteria;
    }

    /**
     * A blank answer: one not_met entry per criterion 1..n, keeping the
     * model's evidence note where it gave one. Ids outside 1..n are dropped.
     *
     * @param  list<array<string, mixed>>  $criteria
     * @return list<array<string, mixed>>
     */
    private static function blankCriteria(array $criteria, int $count): array
    {
        $given = [];
        foreach ($criteria as $c) {
            $given[(int) $c['criterion_id']] ??= $c;
        }
        $out = [];
        for ($id = 1; $id <= $count; $id++) {
            $evidence = (string) ($given[$id]['evidence_th'] ?? '');
            $out[] = ['criterion_id' => $id, 'level' => 'not_met'] + ($evidence !== '' ? ['evidence_th' => $evidence] : []);
        }

        return $out;
    }
}
