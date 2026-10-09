<?php

namespace App\Domain\Exams;

use App\Domain\Worksheets\ArucoMarkers;
use App\Domain\Worksheets\WorksheetGeometry;

/**
 * The pages of an exam answer sheet with every bubble in page millimetres
 * (DESIGN §22.7). Built by ExamSheetLayout, drawn by ExamSheetPdfRenderer
 * and turned into the `layouts.pages` JSON of §22.8 by toLayoutPages(), so
 * the printed sheet and the coordinates the phone reads are one source.
 *
 * Page shape (mm):
 *   page, bands,
 *   version: list<{value, label, cx, cy, r}> (page 1 of a multi-version exam, else []),
 *   code: {rect, columns: list<{col, x, bubbles: list<{value, cx, cy, r}>}>} | null (the student-ID grid, §22.19),
 *   rows: list<{sheet_no, column, row, labels, rect: {x,y,w,h}, bubbles: list<{value, label, cx, cy, r}>}>,
 *   blocks: list<{sheet_no, column, band, rect, sign: {cx,cy,r}|null,
 *                 columns: list<{col, x, bubbles: list<{value, cx, cy, r}>}>}>
 */
final readonly class ExamSheetPlan
{
    /** region_id of the student-ID grid in the layout JSON (§22.19). */
    public const CODE_REGION = 'student_code';

    /** Its sheet number: the fill travels as `digits["0"]`. */
    public const CODE_SHEET_NO = 0;

    /**
     * @param  list<array<string, mixed>>  $pages
     */
    public function __construct(public array $pages, public int $versionCount, public int $codeDigits = 0) {}

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function overflows(): bool
    {
        return $this->pageCount() > ExamSheetCapacity::MAX_PAGES;
    }

    /**
     * The `layouts.pages` value: one DESIGN §22.8 object per page, all
     * coordinates relative to the marker frame (§5.3) and radii by its width.
     *
     * @return list<array<string, mixed>>
     */
    public function toLayoutPages(int $assignmentId, int $version, ArucoMarkers $markers): array
    {
        $out = [];
        foreach ($this->pages as $page) {
            $regions = [];
            if ($page['version'] !== []) {
                $regions[] = [
                    'region_id' => 'version',
                    'kind' => 'version_bubbles',
                    'bubbles' => array_map(fn (array $b) => ['value' => $b['value'], 'label' => $b['label'], ...self::circle($b)], $page['version']),
                ];
            }
            if (($page['code'] ?? null) !== null) {
                // A digit_block at sheet number 0 (no question has it), so the
                // phone reads it with the code that reads numeric answers.
                $regions[] = [
                    'region_id' => self::CODE_REGION,
                    'kind' => 'digit_block',
                    'sheet_no' => self::CODE_SHEET_NO,
                    'rect' => self::rect($page['code']['rect']),
                    'sign' => null,
                    'columns' => array_map(fn (array $c) => [
                        'col' => $c['col'],
                        'bubbles' => array_map(fn (array $b) => ['value' => $b['value'], ...self::circle($b)], $c['bubbles']),
                    ], $page['code']['columns']),
                ];
            }
            foreach ($page['rows'] as $row) {
                $regions[] = [
                    'region_id' => 's'.$row['sheet_no'],
                    'kind' => 'omr_row',
                    'sheet_no' => $row['sheet_no'],
                    'rect' => self::rect($row['rect']),
                    'bubbles' => array_map(fn (array $b) => ['value' => $b['value'], 'label' => $b['label'], ...self::circle($b)], $row['bubbles']),
                ];
            }
            foreach ($page['blocks'] as $block) {
                $regions[] = [
                    'region_id' => 's'.$block['sheet_no'],
                    'kind' => 'digit_block',
                    'sheet_no' => $block['sheet_no'],
                    'rect' => self::rect($block['rect']),
                    'sign' => $block['sign'] === null ? null : self::circle($block['sign']),
                    'columns' => array_map(fn (array $c) => [
                        'col' => $c['col'],
                        'bubbles' => array_map(fn (array $b) => ['value' => $b['value'], ...self::circle($b)], $c['bubbles']),
                    ], $block['columns']),
                ];
            }

            $out[] = [
                'assignment_id' => $assignmentId,
                'version' => $version,
                'page' => $page['page'],
                'page_count' => $this->pageCount(),
                'sheet' => 'exam',
                'marker' => $markers->layoutJson(),
                'frame_mm' => WorksheetGeometry::frameMm(),
                'answer_area' => ExamSheetGeometry::answerArea(),
                'regions' => $regions,
            ];
        }

        return $out;
    }

    /**
     * @param  array{cx: float, cy: float, r: float}  $b
     * @return array{cx: float, cy: float, r: float}
     */
    private static function circle(array $b): array
    {
        return ['cx' => WorksheetGeometry::nx($b['cx']), 'cy' => WorksheetGeometry::ny($b['cy']), 'r' => WorksheetGeometry::nr($b['r'])];
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $r
     * @return array{x: float, y: float, w: float, h: float}
     */
    private static function rect(array $r): array
    {
        return WorksheetGeometry::rect($r['x'], $r['y'], $r['w'], $r['h']);
    }
}
