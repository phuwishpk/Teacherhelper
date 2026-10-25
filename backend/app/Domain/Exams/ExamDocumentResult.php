<?php

namespace App\Domain\Exams;

use App\Domain\Assignments\QuestionData;
use App\Domain\Gemini\GeminiException;
use App\Models\ExamSection;
use App\Models\QuestionOption;

/**
 * What Gemini read from a teacher's exam file (prompt exam_read, DESIGN
 * §22.4), normalised, and the form it is cached in for the whole school
 * (document_extractions.result, purpose exam):
 *
 *   {kind: exam, notes_th,
 *    sections: [{title, instructions, type: mcq|true_false|numeric,
 *                option_count (mcq) | null,
 *                numeric: {digits, allow_negative, allow_decimal} | null,
 *                questions: [{number, text, figure, lock_options,
 *                             options: [{position, text, figure}],
 *                             answer: {accepted_options: [..]} | {accepted_values: [..]} | null}]}],
 *    skipped: [{number, reason_th}]}
 *
 * figure: {sha256, page, box_2d: [ymin, xmin, ymax, xmax]} or null. Gemini
 * names a file by its place in the request (file 1 = first) and a page
 * within what it was sent; the result names the file by its SHA-256 and
 * the page within the whole file (a page range is shifted back), so the
 * cached read means the same whatever order or row the files come in.
 * exam_imports maps the SHA-256 to the source_document_id of each import.
 *
 * Answers only come from a key printed in the file; option labels (ก–ฉ,
 * A–F, 1–6, ถูก/ผิด) become original positions and numbers their canonical
 * form (NumericAnswer). Whether a number fits the section is checked when
 * the read is applied. Nothing here is approved: every question is a draft.
 */
final class ExamDocumentResult
{
    private const MAX_TITLE = 255;

    private const MAX_REASON = 300;

    private const TRUE_LABELS = ['ถูก', 'ถ', 'true', 't', '✓', '/'];

    private const FALSE_LABELS = ['ผิด', 'ผ', 'false', 'f', '✗', 'x'];

    /**
     * @param  array<string, mixed>  $data  Gemini's output (schema-checked)
     * @param  list<array{sha256: string, pages: int, page_offset: int}>  $files  the files in the order sent:
     *                                                                            pages sent and the first page's offset in its file
     * @return array<string, mixed>
     *
     * @throws GeminiException invalid output when the file gave nothing usable
     */
    public static function fromGemini(array $data, array $files): array
    {
        $result = [
            'kind' => 'exam',
            'notes_th' => self::text($data['notes_th'] ?? null, 1000) ?? '',
            'sections' => [],
            'skipped' => [],
        ];

        foreach ((array) ($data['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            $normalised = self::section($section, $files);
            if ($normalised !== null) {
                $result['sections'][] = $normalised;
            }
        }

        foreach ((array) ($data['skipped'] ?? []) as $item) {
            $number = is_array($item) ? self::int($item['number'] ?? null, 1, 1000) : null;
            if ($number === null) {
                continue;
            }
            $result['skipped'][] = [
                'number' => $number,
                'reason_th' => self::text($item['reason_th'] ?? null, self::MAX_REASON) ?? 'ฝนคำตอบไม่ได้',
            ];
        }

        if ($result['sections'] === [] && $result['skipped'] === []) {
            throw GeminiException::invalidOutput('nothing usable in the exam document');
        }

        return $result;
    }

    /** Questions of a normalised result. */
    public static function questionCount(array $result): int
    {
        return array_sum(array_map(fn ($s) => count((array) ($s['questions'] ?? [])), (array) ($result['sections'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  list<array{sha256: string, pages: int, page_offset: int}>  $files
     * @return array<string, mixed>|null
     */
    private static function section(array $section, array $files): ?array
    {
        $type = in_array($section['type'] ?? null, ExamSection::TYPES, true) ? $section['type'] : null;
        $questions = array_values(array_filter((array) ($section['questions'] ?? []), 'is_array'));
        if ($type === null || $questions === []) {
            return null;
        }

        $optionCount = null;
        if ($type === ExamSection::TYPE_MCQ) {
            $given = self::int($section['option_count'] ?? null, ExamSection::MIN_OPTIONS, ExamSection::MAX_OPTIONS);
            $most = max(array_map(fn (array $q) => count(array_filter((array) ($q['options'] ?? []), 'is_array')), $questions));
            $optionCount = $given ?? max(ExamSection::MIN_OPTIONS, min(ExamSection::MAX_OPTIONS, $most > 0 ? $most : 4));
        }

        $out = [];
        foreach ($questions as $question) {
            $out[] = self::question($question, $type, $optionCount, $files);
        }

        $numeric = null;
        if ($type === ExamSection::TYPE_NUMERIC) {
            $given = is_array($section['numeric'] ?? null) ? $section['numeric'] : [];
            $values = [];
            foreach ($out as $q) {
                array_push($values, ...(array) ($q['answer']['accepted_values'] ?? []));
            }
            $needed = max([0, ...array_map(fn (string $v) => strlen(str_replace(['-', '.'], '', preg_replace('/^(-?)0\./', '$1.', $v) ?? $v)), $values)]);
            $numeric = [
                'digits' => self::int($given['digits'] ?? null, 1, ExamSection::MAX_DIGITS) ?? max(1, min(ExamSection::MAX_DIGITS, $needed ?: 3)),
                'allow_negative' => ($given['allow_negative'] ?? false) === true || array_filter($values, fn ($v) => str_starts_with($v, '-')) !== [],
                'allow_decimal' => ($given['allow_decimal'] ?? false) === true || array_filter($values, fn ($v) => str_contains($v, '.')) !== [],
            ];
        }

        return [
            'title' => self::text($section['title'] ?? null, self::MAX_TITLE),
            'instructions' => self::text($section['instructions'] ?? null, ExamEditor::MAX_INSTRUCTIONS),
            'type' => $type,
            'option_count' => $optionCount,
            'numeric' => $numeric,
            'questions' => $out,
        ];
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  list<array{sha256: string, pages: int, page_offset: int}>  $files
     * @return array<string, mixed>
     */
    private static function question(array $question, string $type, ?int $optionCount, array $files): array
    {
        $options = [];
        $labels = [];
        if ($type === ExamSection::TYPE_MCQ) {
            $given = array_values(array_filter((array) ($question['options'] ?? []), 'is_array'));
            for ($p = 1; $p <= (int) $optionCount; $p++) {
                $option = $given[$p - 1] ?? [];
                $label = self::label($option['label'] ?? null);
                if ($label !== '') {
                    $labels[$label] ??= $p;
                }
                $options[] = [
                    'position' => $p,
                    'text' => self::text($option['text'] ?? null, ExamEditor::MAX_OPTION_TEXT),
                    'figure' => self::figure($option['figure'] ?? null, $files),
                ];
            }
        }

        return [
            'number' => self::int($question['number'] ?? null, 1, 1000),
            'text' => self::text($question['text'] ?? null, QuestionData::MAX_PROMPT) ?? '',
            'figure' => self::figure($question['figure'] ?? null, $files),
            'options' => $options,
            'answer' => self::answer($question['answer'] ?? null, $type, (int) $optionCount, $labels),
            'lock_options' => $type === ExamSection::TYPE_MCQ && ($question['lock_options'] ?? false) === true,
        ];
    }

    /**
     * @param  array<string, int>  $labels  printed label => position
     * @return array{accepted_options: list<int>}|array{accepted_values: list<string>}|null
     */
    private static function answer(mixed $answer, string $type, int $optionCount, array $labels): ?array
    {
        if (! is_array($answer)) {
            return null;
        }
        if ($type === ExamSection::TYPE_NUMERIC) {
            $values = [];
            foreach ((array) ($answer['values'] ?? []) as $value) {
                $canonical = is_string($value) || is_int($value) || is_float($value)
                    ? NumericAnswer::canonical(str_replace([',', ' '], '', (string) $value))
                    : null;
                if ($canonical !== null && ! in_array($canonical, $values, true)) {
                    $values[] = $canonical;
                }
            }

            return $values === [] ? null : ['accepted_values' => array_slice($values, 0, ExamAnswerKey::MAX_VALUES)];
        }

        $positions = [];
        foreach ((array) ($answer['options'] ?? []) as $label) {
            $position = $type === ExamSection::TYPE_TRUE_FALSE
                ? self::trueFalse($label)
                : self::optionPosition($label, $optionCount, $labels);
            if ($position !== null && ! in_array($position, $positions, true)) {
                $positions[] = $position;
            }
        }
        if ($positions === [] || ($type === ExamSection::TYPE_TRUE_FALSE && count($positions) > 1)) {
            return null;
        }
        sort($positions);

        return ['accepted_options' => $positions];
    }

    /** ก–ฉ, A–F or 1–6 (as printed on the options first), else null. */
    private static function optionPosition(mixed $label, int $optionCount, array $labels): ?int
    {
        $label = self::label($label);
        if ($label === '') {
            return null;
        }
        $position = $labels[$label] ?? null;
        if ($position === null) {
            $thai = array_search($label, QuestionOption::LABELS, true);
            $position = match (true) {
                $thai !== false => (int) $thai,
                preg_match('/^[a-f]$/', $label) === 1 => ord($label) - ord('a') + 1,
                preg_match('/^[1-6]$/', $label) === 1 => (int) $label,
                default => null,
            };
        }

        return $position !== null && $position >= 1 && $position <= $optionCount ? $position : null;
    }

    private static function trueFalse(mixed $label): ?int
    {
        $label = self::label($label);

        return match (true) {
            in_array($label, self::TRUE_LABELS, true) => 1,
            in_array($label, self::FALSE_LABELS, true) => 2,
            default => null,
        };
    }

    /** "ข้อ ค." / "(C)" / " c " -> "ค" / "c". */
    private static function label(mixed $label): string
    {
        if (! is_string($label) && ! is_int($label)) {
            return '';
        }
        $label = mb_strtolower(trim((string) $label), 'UTF-8');
        $label = preg_replace('/^ข้อ\s*/u', '', $label) ?? $label;

        return trim($label, " \t.()[]:");
    }

    /**
     * @param  list<array{sha256: string, pages: int, page_offset: int}>  $files
     * @return array{sha256: string, page: int, box_2d: list<int>}|null
     */
    private static function figure(mixed $figure, array $files): ?array
    {
        if (! is_array($figure)) {
            return null;
        }
        $file = self::int($figure['file'] ?? null, 1, count($files));
        if ($file === null) {
            return null;
        }
        $meta = $files[$file - 1];
        $page = self::int($figure['page'] ?? null, 1, max(1, $meta['pages']));
        $box = ExamFigures::box($figure['box_2d'] ?? null);
        if ($page === null || $box === null) {
            return null;
        }

        return ['sha256' => $meta['sha256'], 'page' => $meta['page_offset'] + $page, 'box_2d' => $box];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function int(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $value = (int) $value;

        return $value < $min || $value > $max ? null : $value;
    }
}
