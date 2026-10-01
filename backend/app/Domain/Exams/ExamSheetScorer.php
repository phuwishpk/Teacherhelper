<?php

namespace App\Domain\Exams;

/**
 * Scores one answer-sheet page from the bubble fill (DESIGN §22.3, §22.9).
 * The Dart ExamSheetScorer in the app gives the same results: both run the
 * golden fixtures in tests/fixtures/exam_scoring (copied to
 * app/test/fixtures/exam_scoring).
 *
 * Fill values are the phone's, after the page baseline was subtracted:
 * fill >= 0.45 is a mark, 0.20 <= fill < 0.45 is unclear (§11.6).
 *
 * Rows (mcq, true_false) by displayed position of the version:
 *   one mark in the accepted options      full points
 *   one mark, not accepted                0
 *   no mark                               0 (no_answer, not a doubt)
 *   several marks                         0, double_mark
 *   an unclear bubble                     scored from the clear marks, ambiguous_mark
 *
 * Digit blocks (numeric): the sign bubble and one bubble per column
 * ("." or 0–9) read left to right; blank columns before and after the
 * number are fine. Two marks in a column, a blank column between marks,
 * more than one ".", or only a sign and/or "." is invalid_number (0). The
 * text in NumericAnswer::canonical form must equal an accepted value.
 *
 * The version: a one-version exam is version 1 (single); page 1 of a
 * multi-version exam needs exactly one marked version bubble (bubble, with
 * version_doubtful when another one is unclear); a later page takes the
 * version of page 1 of the same student (page_one); otherwise unknown.
 */
final class ExamSheetScorer
{
    public const FILLED_FROM = 0.45;

    public const AMBIGUOUS_FROM = 0.20;

    public const DOUBLE_MARK = 'double_mark';

    public const AMBIGUOUS_MARK = 'ambiguous_mark';

    public const INVALID_NUMBER = 'invalid_number';

    public const VERSION_DOUBTFUL = 'version_doubtful';

    /** Doubts that send an answer to the review queue. */
    public const REVIEW_DOUBTS = [self::DOUBLE_MARK, self::AMBIGUOUS_MARK, self::INVALID_NUMBER];

    /**
     * @param  array<int|string, mixed>|null  $versionFill  {"1": 0.03, "2": 0.91}
     * @return array{version_no: int|null, source: string|null, doubtful: bool}
     */
    public static function version(int $versionCount, int $page, ?array $versionFill, ?int $pageOneVersion): array
    {
        if ($versionCount <= 1) {
            return ['version_no' => 1, 'source' => 'single', 'doubtful' => false];
        }
        if ($page > 1) {
            return $pageOneVersion === null
                ? ['version_no' => null, 'source' => null, 'doubtful' => false]
                : ['version_no' => $pageOneVersion, 'source' => 'page_one', 'doubtful' => false];
        }
        [$marked, $unclear] = self::marks($versionFill ?? []);
        $marked = array_values(array_filter($marked, fn (int $v) => $v >= 1 && $v <= $versionCount));
        if (count($marked) !== 1) {
            return ['version_no' => null, 'source' => null, 'doubtful' => false];
        }

        return ['version_no' => $marked[0], 'source' => 'bubble', 'doubtful' => $unclear !== []];
    }

    /**
     * Scores the rows and digit blocks of a page against the key of its
     * version. Readings without a key item (not on this exam) are skipped.
     *
     * @param  array<int|string, array<string, mixed>>  $keyBySheetNo  sheet_no => {type, points, accepted_options (displayed) | accepted_values}
     * @param  array<int|string, array<int|string, mixed>>  $rows  sheet_no => {displayed position => fill}
     * @param  array<int|string, array<string, mixed>>  $digits  sheet_no => {sign: fill|null, columns: list<{value => fill}>}
     * @return array{items: list<array{sheet_no: int, selected: list<int>, value: string|null, score: float, max: float, doubts: list<string>}>, score: float, max_score: float}
     */
    public static function scorePage(array $keyBySheetNo, array $rows, array $digits): array
    {
        $items = [];
        foreach ($rows as $sheetNo => $fill) {
            $key = $keyBySheetNo[(int) $sheetNo] ?? null;
            if ($key === null) {
                continue;
            }
            $items[] = self::scoreRow((int) $sheetNo, $key, is_array($fill) ? $fill : []);
        }
        foreach ($digits as $sheetNo => $block) {
            $key = $keyBySheetNo[(int) $sheetNo] ?? null;
            if ($key === null) {
                continue;
            }
            $items[] = self::scoreDigits((int) $sheetNo, $key, is_array($block) ? $block : []);
        }
        usort($items, fn (array $a, array $b) => $a['sheet_no'] <=> $b['sheet_no']);

        return [
            'items' => $items,
            'score' => round(array_sum(array_column($items, 'score')), 2),
            'max_score' => round(array_sum(array_column($items, 'max')), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<int|string, mixed>  $fill
     * @return array{sheet_no: int, selected: list<int>, value: null, score: float, max: float, doubts: list<string>}
     */
    public static function scoreRow(int $sheetNo, array $key, array $fill): array
    {
        $reading = self::readRow($fill);
        $accepted = array_map('intval', (array) ($key['accepted_options'] ?? []));
        $max = round((float) ($key['points'] ?? 0), 2);
        $right = count($reading['selected']) === 1 && in_array($reading['selected'][0], $accepted, true);

        return [
            'sheet_no' => $sheetNo,
            'selected' => $reading['selected'],
            'value' => null,
            'score' => $right ? $max : 0.0,
            'max' => $max,
            'doubts' => $reading['doubts'],
        ];
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $block
     * @return array{sheet_no: int, selected: list<int>, value: string|null, score: float, max: float, doubts: list<string>}
     */
    public static function scoreDigits(int $sheetNo, array $key, array $block): array
    {
        $reading = self::readDigits($block);
        $accepted = array_map('strval', (array) ($key['accepted_values'] ?? []));
        $max = round((float) ($key['points'] ?? 0), 2);
        $right = $reading['value'] !== null && in_array($reading['value'], $accepted, true);

        return [
            'sheet_no' => $sheetNo,
            'selected' => [],
            'value' => $reading['value'],
            'score' => $right ? $max : 0.0,
            'max' => $max,
            'doubts' => $reading['doubts'],
        ];
    }

    /**
     * The marked displayed positions of a row and its doubts.
     *
     * @param  array<int|string, mixed>  $fill
     * @return array{selected: list<int>, doubts: list<string>}
     */
    public static function readRow(array $fill): array
    {
        [$marked, $unclear] = self::marks($fill);
        $doubts = [];
        if (count($marked) > 1) {
            $doubts[] = self::DOUBLE_MARK;
        }
        if ($unclear !== []) {
            $doubts[] = self::AMBIGUOUS_MARK;
        }

        return ['selected' => $marked, 'doubts' => $doubts];
    }

    /**
     * The canonical number of a digit block, null when blank or invalid.
     *
     * @param  array<string, mixed>  $block  {sign: fill|null, columns: list<{value => fill}>}
     * @return array{value: string|null, doubts: list<string>}
     */
    public static function readDigits(array $block): array
    {
        $unclear = false;
        $invalid = false;
        $sign = $block['sign'] ?? null;
        $negative = false;
        if (is_numeric($sign)) {
            $negative = (float) $sign >= self::FILLED_FROM;
            $unclear = ! $negative && (float) $sign >= self::AMBIGUOUS_FROM;
        }

        $chars = [];
        foreach (array_values((array) ($block['columns'] ?? [])) as $column) {
            $marked = [];
            foreach ((array) $column as $value => $fill) {
                $fill = (float) $fill;
                if ($fill >= self::FILLED_FROM) {
                    $marked[] = (string) $value;
                } elseif ($fill >= self::AMBIGUOUS_FROM) {
                    $unclear = true;
                }
            }
            if (count($marked) > 1) {
                $invalid = true;
            }
            $chars[] = count($marked) === 1 ? $marked[0] : '';
        }

        $filled = array_keys(array_filter($chars, fn (string $c) => $c !== ''));
        $text = implode('', $chars);
        $blank = $filled === [] && ! $negative;
        if (! $blank) {
            if ($filled !== [] && count($filled) !== max($filled) - min($filled) + 1) {
                $invalid = true; // a blank column between marks
            }
            if (substr_count($text, '.') > 1 || preg_match('/\d/', $text) !== 1) {
                $invalid = true; // two dots, or only a sign and/or a dot
            }
        }

        $value = null;
        if (! $blank && ! $invalid) {
            $value = NumericAnswer::canonical(($negative ? '-' : '').$text);
            $invalid = $value === null;
        }

        $doubts = [];
        if ($invalid) {
            $doubts[] = self::INVALID_NUMBER;
        }
        if ($unclear) {
            $doubts[] = self::AMBIGUOUS_MARK;
        }

        return ['value' => $invalid ? null : $value, 'doubts' => $doubts];
    }

    /**
     * Marked and unclear keys of a {position => fill} map, as sorted ints.
     *
     * @param  array<int|string, mixed>  $fill
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function marks(array $fill): array
    {
        $marked = [];
        $unclear = [];
        foreach ($fill as $position => $value) {
            if (! is_numeric($value)) {
                continue;
            }
            if ((float) $value >= self::FILLED_FROM) {
                $marked[] = (int) $position;
            } elseif ((float) $value >= self::AMBIGUOUS_FROM) {
                $unclear[] = (int) $position;
            }
        }
        sort($marked);
        sort($unclear);

        return [$marked, $unclear];
    }
}
