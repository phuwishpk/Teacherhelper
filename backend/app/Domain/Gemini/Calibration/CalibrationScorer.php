<?php

namespace App\Domain\Gemini\Calibration;

use App\Domain\Grading\AnswerMatcher;
use App\Domain\Grading\ResponseGrader;
use App\Models\Question;
use Throwable;

/**
 * The arithmetic of the calibration harness (DESIGN §21.10), no I/O:
 *
 * - score(): one sample against its label: answer (the text read matches
 *   the label after the §11.4 normalisation, spaces ignored), category
 *   (key_match / final_answer_match + every step's `valid` / criteria
 *   levels / the question type of a key) and full (fuzzy gives full marks);
 *   a sample the model did not answer counts as wrong;
 * - summary(): accuracies of one (kind, level) run;
 * - verdict(): a lower level passes when it has at least min_samples, its
 *   answer and category accuracy drop at most max_drop below `high`, and
 *   at most max_score_flips samples move between full and not full marks;
 * - recommend(): the lowest level that passed, else `high` (§21.5: low
 *   fails -> medium, medium fails -> high);
 * - cnnSkip(): the CNN-skip rule (§21.3) on the samples with a known digit
 *   reading: how many it would decide and how many of those wrongly.
 */
final class CalibrationScorer
{
    public const LEVELS = ['low', 'medium', 'high'];

    /** .env setting of each kind (config services.gemini.media.*). */
    public const ENV = [
        'short' => 'GEMINI_MEDIA_SHORT',
        'work' => 'GEMINI_MEDIA_WORK',
        'page' => 'GEMINI_MEDIA_PAGE',
        'document' => 'GEMINI_MEDIA_DOCUMENT',
    ];

    /**
     * @param  array<string, mixed>|null  $read  the extraction (or, for a document, the key row) read by Gemini
     * @return array{id: string, type: string, found: bool, read: string|null, expected: string|null, answer: bool|null, category: bool|null, full: bool|null}
     */
    public static function score(CalibrationSample $sample, ?array $read, bool $document = false): array
    {
        $type = $sample->question->type;
        $label = $sample->label;
        $expected = self::expected($type, $label, $document);
        $row = ['id' => $sample->id, 'type' => $type, 'found' => $read !== null, 'read' => null, 'expected' => $expected];
        if ($read === null) {
            return $row + ['answer' => $expected === null ? null : false, 'category' => false, 'full' => null];
        }

        if ($document) {
            $key = (array) ($read['answer_key'] ?? []);
            $given = $type === Question::TYPE_MCQ
                ? array_filter([(string) ($key['correct'] ?? '')])
                : array_map('strval', (array) ($key['accepted'] ?? $key['final']['accepted'] ?? []));
            $numeric = $key['numeric']['value'] ?? $key['final']['numeric']['value'] ?? null;
            $answer = $expected === null ? null : (self::inList($expected, $given)
                || ($numeric !== null && AnswerMatcher::parseNumber($expected) !== null && abs((float) $numeric - (float) AnswerMatcher::parseNumber($expected)) < 1e-9));

            return array_merge($row, [
                'read' => implode(' | ', $given),
                'answer' => $answer,
                'category' => ($read['type'] ?? null) === $type,
                'full' => null,
            ]);
        }

        [$text, $category] = match ($type) {
            Question::TYPE_SHORT => [(string) ($read['answer_text'] ?? ''), self::oneOf($read['key_match'] ?? null, $label['key_match'] ?? null)],
            Question::TYPE_SHOW_WORK => [(string) ($read['final_answer_text'] ?? ''), self::oneOf($read['final_answer_match'] ?? null, $label['final_answer_match'] ?? null)
                && self::stepsMatch((array) ($read['steps'] ?? []), $label['steps_valid'] ?? null)],
            Question::TYPE_OPEN => [(string) ($read['transcription'] ?? ''), self::levelsMatch((array) ($read['criteria'] ?? []), (array) ($label['criteria_levels'] ?? []))],
            default => [implode(',', (array) ($read['selected_options'] ?? [])), array_values((array) ($read['selected_options'] ?? [])) === [(string) ($label['selected'] ?? '')]],
        };

        return array_merge($row, [
            'read' => $text,
            'answer' => $expected === null ? null : self::same($text, $expected),
            'category' => $category,
            'full' => self::full($sample, $read),
        ]);
    }

    /**
     * @param  list<array{answer: bool|null, category: bool|null, found: bool}>  $rows
     * @return array{samples: int, found: int, answer_accuracy: float|null, category_accuracy: float|null}
     */
    public static function summary(array $rows): array
    {
        $rate = function (string $field) use ($rows): ?float {
            $measured = array_values(array_filter($rows, fn (array $r) => $r[$field] !== null));

            return $measured === [] ? null : round(count(array_filter($measured, fn (array $r) => $r[$field] === true)) / count($measured), 4);
        };

        return [
            'samples' => count($rows),
            'found' => count(array_filter($rows, fn (array $r) => $r['found'])),
            'answer_accuracy' => $rate('answer'),
            'category_accuracy' => $rate('category'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $baseline  rows at `high`
     * @param  list<array<string, mixed>>  $target  rows at the level under test
     * @param  array{min_samples: int, max_drop: float, max_score_flips: int}  $limits
     * @return array{pass: bool, samples: int, answer_drop: float, category_drop: float, score_flips: int, flipped: list<string>, reasons: list<string>}
     */
    public static function verdict(array $baseline, array $target, array $limits): array
    {
        $base = self::summary($baseline);
        $test = self::summary($target);
        $answerDrop = round(($base['answer_accuracy'] ?? 0.0) - ($test['answer_accuracy'] ?? $base['answer_accuracy'] ?? 0.0), 4);
        $categoryDrop = round(($base['category_accuracy'] ?? 0.0) - ($test['category_accuracy'] ?? $base['category_accuracy'] ?? 0.0), 4);

        $baseFull = [];
        foreach ($baseline as $row) {
            $baseFull[$row['id']] = $row['full'];
        }
        $flipped = [];
        foreach ($target as $row) {
            $before = $baseFull[$row['id']] ?? null;
            if ($before !== null && $row['full'] !== null && $before !== $row['full']) {
                $flipped[] = $row['id'];
            }
        }

        $reasons = [];
        if ($test['samples'] < $limits['min_samples']) {
            $reasons[] = "only {$test['samples']} samples (need {$limits['min_samples']})";
        }
        if ($answerDrop > $limits['max_drop'] + 1e-9) {
            $reasons[] = 'answer accuracy drops '.self::points($answerDrop).' (max '.self::points($limits['max_drop']).')';
        }
        if ($categoryDrop > $limits['max_drop'] + 1e-9) {
            $reasons[] = 'category accuracy drops '.self::points($categoryDrop).' (max '.self::points($limits['max_drop']).')';
        }
        if (count($flipped) > $limits['max_score_flips']) {
            $reasons[] = count($flipped).' answers flip between full and not full marks (max '.$limits['max_score_flips'].')';
        }

        return [
            'pass' => $reasons === [],
            'samples' => $test['samples'],
            'answer_drop' => $answerDrop,
            'category_drop' => $categoryDrop,
            'score_flips' => count($flipped),
            'flipped' => $flipped,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string, bool>  $passed  level => verdict of the levels tested below high
     */
    public static function recommend(array $passed): string
    {
        foreach (['low', 'medium'] as $level) {
            if (($passed[$level] ?? false) === true) {
                return $level;
            }
        }

        return 'high';
    }

    /**
     * @param  list<CalibrationSample>  $samples  short samples
     * @param  array{min_samples: int, max_score_flips: int}  $limits
     * @return array{samples: int, decided: int, wrong: int, wrong_ids: list<string>, pass: bool, min_confidence: float}
     */
    public static function cnnSkip(array $samples, float $minConfidence, array $limits): array
    {
        $withReading = array_values(array_filter($samples, fn (CalibrationSample $s) => $s->cnn !== null && $s->question->type === Question::TYPE_SHORT));
        $decided = 0;
        $wrong = [];
        foreach ($withReading as $s) {
            $accepted = array_map('strval', (array) (($s->question->answer_key ?? [])['accepted'] ?? []));
            if ($s->cnn['confidence'] < $minConfidence || ! self::inList($s->cnn['text'], $accepted)) {
                continue;
            }
            $decided++;
            if (! self::inList((string) ($s->label['answer_text'] ?? ''), $accepted)) {
                $wrong[] = $s->id; // full marks for an answer that is not right
            }
        }

        return [
            'samples' => count($withReading),
            'decided' => $decided,
            'wrong' => count($wrong),
            'wrong_ids' => $wrong,
            'pass' => count($withReading) >= $limits['min_samples'] && count($wrong) <= $limits['max_score_flips'],
            'min_confidence' => $minConfidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $label
     */
    private static function expected(string $type, array $label, bool $document): ?string
    {
        if ($document) {
            return isset($label['answer']) ? (string) $label['answer'] : null;
        }

        return match ($type) {
            Question::TYPE_SHORT => isset($label['answer_text']) ? (string) $label['answer_text'] : null,
            Question::TYPE_SHOW_WORK => isset($label['final_answer_text']) ? (string) $label['final_answer_text'] : null,
            Question::TYPE_MCQ => isset($label['selected']) ? (string) $label['selected'] : null,
            default => null,
        };
    }

    private static function same(string $a, string $b): bool
    {
        $squash = fn (string $s) => (string) preg_replace('/[\s\p{Z}]+/u', '', AnswerMatcher::normalize($s));

        return $squash($a) === $squash($b);
    }

    /**
     * @param  list<string>  $list
     */
    private static function inList(string $value, array $list): bool
    {
        foreach ($list as $item) {
            if (self::same($value, $item)) {
                return true;
            }
        }

        return false;
    }

    private static function oneOf(mixed $value, mixed $accepted): bool
    {
        return is_string($value) && in_array($value, array_map('strval', (array) $accepted), true);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private static function stepsMatch(array $steps, mixed $expected): bool
    {
        if (! is_array($expected)) {
            return true;
        }
        usort($steps, fn (array $a, array $b) => (int) ($a['line'] ?? 0) <=> (int) ($b['line'] ?? 0));

        return array_map(fn (array $s) => (bool) ($s['valid'] ?? false), $steps) === array_map('boolval', array_values($expected));
    }

    /**
     * @param  list<array<string, mixed>>  $criteria
     * @param  list<string>  $expected
     */
    private static function levelsMatch(array $criteria, array $expected): bool
    {
        usort($criteria, fn (array $a, array $b) => (int) ($a['criterion_id'] ?? 0) <=> (int) ($b['criterion_id'] ?? 0));

        return $expected !== [] && array_map(fn (array $c) => (string) ($c['level'] ?? ''), $criteria) === array_values($expected);
    }

    /**
     * @param  array<string, mixed>  $read
     */
    private static function full(CalibrationSample $sample, array $read): ?bool
    {
        $question = $sample->question;
        if ($question->type === Question::TYPE_MCQ) {
            $correct = (string) (($question->answer_key ?? [])['correct'] ?? '');

            return $correct === '' ? null : array_values((array) ($read['selected_options'] ?? [])) === [$correct];
        }
        try {
            $grade = ResponseGrader::grade($question, $sample->criteria, 'normal', $read);
        } catch (Throwable) {
            return null;
        }

        return $grade->isScored() && $grade->score !== null ? $grade->score >= (float) $question->max_points - 0.001 : null;
    }

    private static function points(float $drop): string
    {
        return rtrim(rtrim(number_format($drop * 100, 1), '0'), '.').' points';
    }
}
