<?php

namespace App\Domain\Worksheets;

use App\Models\Assignment;
use App\Models\Question;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;

/**
 * Lays out an assignment's questions on A4 pages (DESIGN §5.1, §5.3).
 *
 * Prompt heights are not estimated: each prompt is rendered with the same
 * mPDF configuration the worksheet uses (Sarabun, Thai dictionary line
 * breaking) and its real height is read back. Answer areas have fixed sizes
 * per type. A question is never split across pages: when the rest of the page
 * is too short, the whole question moves to the next page.
 */
class LayoutBuilder
{
    public function __construct(private readonly WorksheetMpdfFactory $mpdfFactory) {}

    /**
     * @param  iterable<Question>|null  $questions  defaults to the assignment's questions ordered by position
     *
     * @throws WorksheetLayoutException
     */
    public function plan(Assignment $assignment, ?iterable $questions = null): WorksheetPlan
    {
        $questions ??= $assignment->relationLoaded('questions')
            ? $assignment->questions
            : $assignment->questions()->get();

        $measurer = null;
        $pages = [];
        $current = [];
        $y = WorksheetGeometry::CONTENT_TOP;
        $number = 0;

        foreach ($questions as $question) {
            $number++;
            $measurer ??= $this->mpdfFactory->create();
            $html = PromptHtml::for($question, $number);
            $promptHeight = $this->measure($measurer, $html, $number);
            $height = PlacedQuestion::blockHeight($question, $promptHeight);

            if ($height > WorksheetGeometry::contentHeight()) {
                throw new WorksheetLayoutException(
                    "ข้อ {$number} ยาวเกินหนึ่งหน้า ลดจำนวนบรรทัดคำตอบหรือทำโจทย์ให้สั้นลง",
                    $number,
                );
            }

            if ($current !== [] && $y + $height > WorksheetGeometry::CONTENT_BOTTOM) {
                $pages[] = $current;
                $current = [];
                $y = WorksheetGeometry::CONTENT_TOP;
            }

            $current[] = new PlacedQuestion($question, $number, $html, $y, $promptHeight);
            $y += $height + WorksheetGeometry::QUESTION_GAP;
        }

        if ($current !== []) {
            $pages[] = $current;
        }

        return new WorksheetPlan($pages);
    }

    /**
     * The `layouts.pages` JSON for this assignment at the given version.
     *
     * @return list<array<string, mixed>>
     *
     * @throws WorksheetLayoutException
     */
    public function build(Assignment $assignment, int $version, ?ArucoMarkers $markers = null): array
    {
        return $this->plan($assignment)->toLayoutPages($assignment->id, $version, $markers ?? ArucoMarkers::load());
    }

    /** Height in mm of the prompt as mPDF actually typesets it, measured on a fresh page. */
    private function measure(Mpdf $mpdf, string $html, int $number): float
    {
        $mpdf->AddPage();
        $page = $mpdf->page;
        $mpdf->SetY(WorksheetGeometry::CONTENT_TOP);
        $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);

        if ($mpdf->page !== $page) {
            throw new WorksheetLayoutException("ข้อ {$number} มีโจทย์ยาวเกินหนึ่งหน้า", $number);
        }

        return round($mpdf->y - WorksheetGeometry::CONTENT_TOP, 3);
    }
}
