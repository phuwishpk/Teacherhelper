<?php

namespace App\Domain\Exams;

use App\Domain\Worksheets\ArucoMarkers;
use App\Domain\Worksheets\QrSigner;
use App\Domain\Worksheets\WorksheetGeometry;
use App\Domain\Worksheets\WorksheetLayoutException;
use App\Domain\Worksheets\WorksheetMpdfFactory;
use App\Domain\Worksheets\WorksheetPdfRenderer;
use App\Domain\Worksheets\WorksheetStudent;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Layout;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * Renders exam answer sheets (DESIGN §22.6 item 2–3, §22.7): per student
 * 1–2 A4 pages with the ArUco markers, the worksheet header (title, name,
 * number, room, page x/y), the signed QR
 * `EVX1.{assignment}.{student}.{page}.{layout_version}.{sig}`, the version
 * bubbles on page 1, the grid of numbered bubble rows, the digit blocks and
 * the pencil instructions. A null student prints the teacher's key sheet
 * ("กระดาษเฉลย (สำหรับครู)", student_id 0) with the same layout.
 *
 * Everything is drawn at fixed positions from the same ExamSheetPlan whose
 * normalised form is the stored layout; the plan is checked against the
 * layout first, so the PDF never disagrees with what the phone reads.
 */
class ExamSheetPdfRenderer
{
    public function __construct(
        private readonly WorksheetMpdfFactory $mpdfFactory,
        private readonly QrSigner $qrSigner,
    ) {}

    /**
     * @param  list<WorksheetStudent|null>  $students  null = the key sheet
     * @return string PDF bytes
     *
     * @throws WorksheetLayoutException when the exam no longer matches $layout
     */
    public function render(Assignment $exam, Layout $layout, array $students): string
    {
        if ($students === []) {
            throw new RuntimeException('No students to render.');
        }

        $exam->loadMissing(['classroom', 'course', 'subject']);
        $markers = ArucoMarkers::load();
        try {
            $plan = ExamSheetLayout::forExam($exam);
        } catch (ApiException $e) {
            throw new WorksheetLayoutException($e->getMessage());
        }
        if ($plan->toLayoutPages($exam->id, $layout->version, $markers) != $layout->pages) {
            throw new WorksheetLayoutException(
                "โครงสร้างข้อสอบเปลี่ยนหลังสร้างกระดาษคำตอบเวอร์ชัน {$layout->version} กรุณาสั่งพิมพ์ใหม่",
            );
        }

        $mpdf = $this->mpdfFactory->create();
        $mpdf->SetTitle($exam->title);
        $mpdf->SetCreator('EduVision');
        $mpdf->SetAutoPageBreak(false);

        foreach ($students as $student) {
            foreach ($plan->pages as $page) {
                $mpdf->AddPage();
                WorksheetPdfRenderer::drawMarkers($mpdf, $markers);
                $this->drawHeader($mpdf, $exam, $student, $page['page'], $plan->pageCount());
                WorksheetPdfRenderer::drawQr($mpdf, $this->qrSigner->signExamSheet($exam->id, $student?->id ?? 0, $page['page'], $layout->version));
                if ($page['version'] !== []) {
                    $this->drawVersionBubbles($mpdf, $page['version']);
                }
                $this->drawColumnHeaders($mpdf, $page['rows']);
                foreach ($page['rows'] as $row) {
                    $this->drawRow($mpdf, $row);
                }
                foreach ($page['blocks'] as $block) {
                    $this->drawBlock($mpdf, $block);
                }
                $this->drawFooter($mpdf, $layout->version, $student === null);
            }
        }

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function drawHeader(Mpdf $mpdf, Assignment $exam, ?WorksheetStudent $student, int $page, int $pageCount): void
    {
        $e = fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $code = $exam->course?->code;

        $title = $student === null
            ? 'กระดาษเฉลย (สำหรับครู): '.$e($exam->title)
            : 'กระดาษคำตอบ: '.$e($exam->title).($code !== null && $code !== '' ? ' '.$e($code) : '');
        $mpdf->WriteFixedPosHTML(
            '<div style="font-size: 14pt; font-weight: bold;">'.$title.'</div>',
            WorksheetGeometry::TITLE_X, WorksheetGeometry::TITLE_Y, WorksheetGeometry::TITLE_W, WorksheetGeometry::TITLE_H, 'auto',
        );

        $room = $exam->classroom !== null ? '&nbsp;&nbsp;&nbsp;ห้อง <b>'.$e($exam->classroom->name).'</b>' : '';
        $line = $student === null
            ? ($exam->version_count > 1 ? 'ฝนเฉลยของชุดใดชุดหนึ่ง และฝนวงชุดของเฉลยนั้นด้วย' : 'ฝนเฉลยของแต่ละข้อ')
            : 'ชื่อ <b>'.$e($student->name).'</b>&nbsp;&nbsp;&nbsp;เลขที่ <b>'.$student->number.'</b>'.$room;
        $mpdf->WriteFixedPosHTML(
            '<div style="font-size: 12pt;">'.$line.'</div>',
            WorksheetGeometry::TITLE_X, WorksheetGeometry::STUDENT_Y, WorksheetGeometry::TITLE_W, WorksheetGeometry::STUDENT_H, 'auto',
        );

        $mpdf->WriteFixedPosHTML(
            '<div style="font-size: 11pt; color: #444444;">หน้า '.$page.'/'.$pageCount.'</div>',
            WorksheetGeometry::TITLE_X, WorksheetGeometry::META_Y, WorksheetGeometry::TITLE_W, WorksheetGeometry::META_H, 'auto',
        );

        $mpdf->SetDrawColor(0, 0, 0);
        $mpdf->SetLineWidth(0.3);
        $mpdf->Line(WorksheetGeometry::CONTENT_LEFT, WorksheetGeometry::HEADER_RULE_Y, WorksheetGeometry::CONTENT_RIGHT, WorksheetGeometry::HEADER_RULE_Y);
    }

    /**
     * @param  list<array{value: int, label: string, cx: float, cy: float, r: float}>  $bubbles
     */
    private function drawVersionBubbles(Mpdf $mpdf, array $bubbles): void
    {
        $mpdf->SetFont(WorksheetMpdfFactory::FONT, 'B', 11);
        $mpdf->SetTextColor(0, 0, 0);
        $mpdf->SetXY(ExamSheetGeometry::VERSION_LABEL_X, ExamSheetGeometry::VERSION_Y - 3);
        $mpdf->WriteCell(ExamSheetGeometry::VERSION_X - ExamSheetGeometry::VERSION_R - 2 - ExamSheetGeometry::VERSION_LABEL_X, 6, 'ชุดข้อสอบ', 0, 0, 'R');
        foreach ($bubbles as $bubble) {
            $this->bubble($mpdf, $bubble['cx'], $bubble['cy'], $bubble['r'], $bubble['label'], 8.5);
        }
    }

    /**
     * Option labels above the bubbles of each column that has rows, from
     * its row with the most options.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function drawColumnHeaders(Mpdf $mpdf, array $rows): void
    {
        $widest = [];
        foreach ($rows as $row) {
            if (count($row['bubbles']) > count($widest[$row['column']] ?? [])) {
                $widest[$row['column']] = $row['bubbles'];
            }
        }
        $mpdf->SetFont(WorksheetMpdfFactory::FONT, 'B', 10);
        $mpdf->SetTextColor(0, 0, 0);
        foreach ($widest as $bubbles) {
            foreach ($bubbles as $bubble) {
                $mpdf->SetXY($bubble['cx'] - 2.5, ExamSheetGeometry::COLUMN_HEADER_Y - 2.5);
                $mpdf->WriteCell(5, 5, $bubble['label'], 0, 0, 'C');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function drawRow(Mpdf $mpdf, array $row): void
    {
        $mpdf->SetFont(WorksheetMpdfFactory::FONT, 'B', 10);
        $mpdf->SetTextColor(0, 0, 0);
        $mpdf->SetXY($row['rect']['x'], $row['rect']['y'] + 1);
        $mpdf->WriteCell(ExamSheetGeometry::NUMBER_W, $row['rect']['h'] - 2, (string) $row['sheet_no'], 0, 0, 'R');
        foreach ($row['bubbles'] as $bubble) {
            $this->bubble($mpdf, $bubble['cx'], $bubble['cy'], $bubble['r'], $bubble['label'], 8);
        }
    }

    /**
     * A digit block: outline, "ข้อ n", a box above every column to write
     * the digit in (not read), then the sign, point and 0–9 bubbles.
     *
     * @param  array<string, mixed>  $block
     */
    private function drawBlock(Mpdf $mpdf, array $block): void
    {
        $rect = $block['rect'];
        $mpdf->SetDrawColor(0, 0, 0);
        $mpdf->SetLineWidth(0.3);
        $mpdf->Rect($rect['x'] + 0.6, $rect['y'] + 0.6, $rect['w'] - 1.2, $rect['h'] - 1.2, 'D');

        $mpdf->SetFont(WorksheetMpdfFactory::FONT, 'B', 10);
        $mpdf->SetTextColor(0, 0, 0);
        $mpdf->SetXY($rect['x'] + 2, $rect['y'] + 1);
        $mpdf->WriteCell($rect['w'] - 4, 4.5, 'ข้อ '.$block['sheet_no'], 0, 0, 'L');

        $xs = array_column($block['columns'], 'x');
        if ($block['sign'] !== null) {
            array_unshift($xs, $block['sign']['cx']);
        }
        $mpdf->SetLineWidth(0.2);
        foreach ($xs as $x) {
            $mpdf->Rect($x - 2.5, $rect['y'] + 6, 5, 5.5, 'D');
        }

        if ($block['sign'] !== null) {
            $this->bubble($mpdf, $block['sign']['cx'], $block['sign']['cy'], $block['sign']['r'], '−', 8);
        }
        foreach ($block['columns'] as $column) {
            foreach ($column['bubbles'] as $bubble) {
                $this->bubble($mpdf, $bubble['cx'], $bubble['cy'], $bubble['r'], $bubble['value'], 7.5);
            }
        }
    }

    /** An empty bubble with its label inside in 35 % grey (DESIGN §22.7). */
    private function bubble(Mpdf $mpdf, float $cx, float $cy, float $r, string $label, float $fontSize): void
    {
        $mpdf->SetDrawColor(0, 0, 0);
        $mpdf->SetLineWidth(ExamSheetGeometry::BUBBLE_LINE);
        $mpdf->Circle($cx, $cy, $r, 'D');
        $mpdf->SetFont(WorksheetMpdfFactory::FONT, '', $fontSize);
        $grey = ExamSheetGeometry::LABEL_GREY;
        $mpdf->SetTextColor($grey, $grey, $grey);
        $mpdf->SetXY($cx - $r, $cy - $r);
        $mpdf->WriteCell(2 * $r, 2 * $r, $label, 0, 0, 'C');
        $mpdf->SetTextColor(0, 0, 0);
    }

    private function drawFooter(Mpdf $mpdf, int $version, bool $keySheet): void
    {
        $y = ExamSheetGeometry::FOOTER_Y;
        $mpdf->SetFont(WorksheetMpdfFactory::FONT, 'B', 11);
        $mpdf->SetTextColor(0, 0, 0);
        $mpdf->SetXY(WorksheetGeometry::TITLE_X, $y - 3);
        $mpdf->WriteCell(90, 6, 'ใช้ดินสอ 2B ฝนให้เต็มวง ลบให้สะอาด', 0, 0, 'L');

        // Examples: a filled bubble is right, a crossed one is wrong.
        $mpdf->SetFont(WorksheetMpdfFactory::FONT, '', 11);
        $mpdf->SetXY(122, $y - 3);
        $mpdf->WriteCell(16, 6, 'ตัวอย่าง', 0, 0, 'R');
        $mpdf->SetFillColor(0, 0, 0);
        $mpdf->SetDrawColor(0, 0, 0);
        $mpdf->SetLineWidth(ExamSheetGeometry::BUBBLE_LINE);
        $mpdf->Circle(142, $y, ExamSheetGeometry::BUBBLE_R, 'FD');
        $mpdf->SetXY(145, $y - 3);
        $mpdf->WriteCell(10, 6, 'ถูก', 0, 0, 'L');
        $mpdf->Circle(160, $y, ExamSheetGeometry::BUBBLE_R, 'D');
        $d = ExamSheetGeometry::BUBBLE_R * 0.75;
        $mpdf->Line(160 - $d, $y - $d, 160 + $d, $y + $d);
        $mpdf->Line(160 - $d, $y + $d, 160 + $d, $y - $d);
        $mpdf->SetXY(163, $y - 3);
        $mpdf->WriteCell(10, 6, 'ผิด', 0, 0, 'L');
        $mpdf->SetFillColor(255, 255, 255);

        $mpdf->SetFont(WorksheetMpdfFactory::FONT, '', 8);
        $mpdf->SetTextColor(120, 120, 120);
        $mpdf->SetXY(WorksheetGeometry::CONTENT_LEFT + 12, WorksheetGeometry::FOOTER_Y);
        $mpdf->WriteCell(
            WorksheetGeometry::contentWidth() - 24, 4,
            'EduVision · '.($keySheet ? 'กระดาษเฉลย' : 'กระดาษคำตอบ').' เวอร์ชัน '.$version.' · อย่าพับหรือขีดเขียนบนมุมและ QR',
            0, 0, 'C',
        );
        $mpdf->SetTextColor(0, 0, 0);
    }
}
