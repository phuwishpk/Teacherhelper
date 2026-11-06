<?php

namespace App\Domain\Worksheets;

/**
 * Pagination of an assignment's questions: which question goes on which page
 * and where. Produced by LayoutBuilder from real mPDF measurements, turned
 * into the stored layout JSON, and replayed by WorksheetPdfRenderer.
 */
final readonly class WorksheetPlan
{
    /**
     * @param  list<list<PlacedQuestion>>  $pages
     */
    public function __construct(public array $pages) {}

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /**
     * The `layouts.pages` value: one DESIGN §5.3 object per page.
     *
     * @return list<array<string, mixed>>
     */
    public function toLayoutPages(int $assignmentId, int $version, ArucoMarkers $markers): array
    {
        $pageCount = $this->pageCount();
        $out = [];

        foreach ($this->pages as $index => $placed) {
            $out[] = [
                'assignment_id' => $assignmentId,
                'version' => $version,
                'page' => $index + 1,
                'page_count' => $pageCount,
                'marker' => $markers->layoutJson(),
                'frame_mm' => WorksheetGeometry::frameMm(),
                'regions' => array_map(
                    fn (PlacedQuestion $p) => $p->region()->toLayoutJson($p->question),
                    $placed,
                ),
            ];
        }

        return $out;
    }
}
