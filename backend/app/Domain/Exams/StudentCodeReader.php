<?php

namespace App\Domain\Exams;

/**
 * Reads the student ID a student filled in on a shared answer sheet (DESIGN
 * §22.19) from the fill of its grid (`digits["0"]`, one map of digit => fill
 * per column). The same rules as the app's `StudentCodeReader`; both run
 * the golden fixture tests/fixtures/exam_scoring/codes.json.
 *
 * - A column is a digit when exactly one bubble is filled (fill ≥ 0.45).
 * - Columns left of the first digit may be empty (a short ID is written to
 *   the right); an empty column after it, or two filled bubbles in one
 *   column, make the ID `invalid`.
 * - Any bubble that is neither empty nor filled (0.20 ≤ fill < 0.45) makes
 *   it `unclear`: a faint mark may be a digit, so nothing is guessed.
 * - No filled bubble at all is `blank`.
 *
 * The ID keeps its leading zeros: it is compared as text with
 * users.student_code.
 */
final class StudentCodeReader
{
    public const BLANK = 'blank';

    public const INVALID = 'invalid';

    public const UNCLEAR = 'unclear';

    /**
     * @param  array<string, mixed>|null  $block  {columns: list<{digit => fill}>}
     * @return array{code: string|null, problem: string|null}
     */
    public static function read(?array $block): array
    {
        $unclear = false;
        $invalid = false;
        $chars = [];
        foreach (array_values((array) ($block['columns'] ?? [])) as $column) {
            $marked = [];
            foreach ((array) $column as $value => $fill) {
                $fill = (float) $fill;
                if ($fill >= ExamSheetScorer::FILLED_FROM) {
                    $marked[] = (string) $value;
                } elseif ($fill >= ExamSheetScorer::AMBIGUOUS_FROM) {
                    $unclear = true;
                }
            }
            if (count($marked) > 1) {
                $invalid = true;
            }
            $chars[] = count($marked) === 1 ? $marked[0] : '';
        }

        $code = ltrim(implode(',', $chars), ',');
        if ($code !== '' && (str_contains($code, ',,') || str_ends_with($code, ','))) {
            $invalid = true; // an empty column after the first digit
        }
        $code = str_replace(',', '', $code);
        if ($code !== '' && preg_match('/\A\d+\z/', $code) !== 1) {
            $invalid = true;
        }

        $problem = match (true) {
            $invalid => self::INVALID,
            $unclear => self::UNCLEAR,
            $code === '' => self::BLANK,
            default => null,
        };

        return ['code' => $problem === null ? $code : null, 'problem' => $problem];
    }
}
